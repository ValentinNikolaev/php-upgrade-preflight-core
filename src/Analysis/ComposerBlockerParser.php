<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ScenarioResult;

final class ComposerBlockerParser
{
    private const PACKAGE_PATTERN = '[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*';
    private const PLATFORM_PATTERN = '(?:php(?:-64bit|-ipv6)?|ext-[a-z0-9_.-]+|lib-[a-z0-9_.-]+|composer(?:-plugin-api|-runtime-api)?)';

    /** @return list<Blocker> */
    public function parse(ScenarioResult $result, string $evidenceId): array
    {
        $blockers = [];

        foreach ($result->diagnostics() as $diagnostic) {
            $blocker = $this->fromDiagnostic($diagnostic, $evidenceId);
            if ($blocker !== null) {
                $blockers[] = $blocker;
            }
        }

        if ($blockers !== []) {
            return $blockers;
        }

        $output = trim($result->stdout() . "\n" . $result->stderr());

        return array_map(
            fn (string $problem): Blocker => $this->fromOutput($problem, $result, $evidenceId),
            $this->problemSections($output)
        );
    }

    private function fromDiagnostic(ComposerDiagnostic $diagnostic, string $evidenceId): ?Blocker
    {
        $output = trim($diagnostic->stdout() . "\n" . $diagnostic->stderr());
        if ($output === '') {
            return null;
        }

        $relations = $this->relations($output);
        if ($relations === []) {
            return null;
        }

        $subject = strtolower($diagnostic->package());
        $relation = null;
        foreach ($relations as $candidate) {
            if ($candidate['dependency'] === $subject) {
                $relation = $candidate;
                break;
            }
        }
        $relation = $relation ?? $relations[0];
        $subject = $relation['dependency'];
        $type = $this->relationType($relation['operation'], $subject, $diagnostic->constraint(), $relation['constraint']);

        return $this->blocker(
            $type,
            $subject,
            $diagnostic->constraint(),
            $relation['package'],
            $relation['version'],
            $relation['constraint'],
            $this->dependencyPath($relations, $subject),
            $evidenceId,
            'high'
        );
    }

    private function fromOutput(string $output, ScenarioResult $result, string $evidenceId): Blocker
    {
        $package = self::PACKAGE_PATTERN;
        $platform = self::PLATFORM_PATTERN;

        if (preg_match('~Package\s+(' . $package . ')\s+is abandoned(?:, you should avoid using it)?(?:\. Use\s+(' . $package . ')\s+instead)?~i', $output, $matches) === 1) {
            $subject = strtolower($matches[1]);
            $options = isset($matches[2])
                ? [sprintf('Replace `%s` with `%s`.', $subject, strtolower($matches[2]))]
                : [sprintf('Replace `%s` with a maintained alternative.', $subject)];

            return new Blocker('abandoned-package', $subject, 'Composer reported an abandoned package.', 'high', [$evidenceId], $this->requestedConstraint($result, $subject), null, null, null, [$subject], $options);
        }

        if (preg_match('~(?:(' . $package . ')\s+([^\s]+)\s+requires\s+)?(ext-[a-z0-9_.-]+)\s+([^\s,;)]+).*?(?:missing from your system|is missing)~is', $output, $matches) === 1) {
            $subject = strtolower($matches[3]);
            $blockingPackage = $matches[1] !== '' ? strtolower($matches[1]) : null;

            return $this->blocker('extension-missing', $subject, $this->requestedConstraint($result, $subject), $blockingPackage, $matches[2] !== '' ? $matches[2] : null, $this->cleanConstraint($matches[4]), $blockingPackage === null ? [$subject] : [$blockingPackage, $subject], $evidenceId, 'high');
        }

        if (preg_match('~(' . $package . ')(?:\s+([^\s]+))?\s+requires\s+php(?:-64bit)?\s+(.+?)(?=\s+->|\R|$)~i', $output, $matches) === 1) {
            $requested = $this->requestedConstraint($result, 'php');
            if ($requested === null && preg_match('/your php version \((?:[^0-9]*)(\d+(?:\.\d+){1,3})/i', $output, $version) === 1) {
                $requested = $version[1];
            }
            $conflict = $this->cleanConstraint($matches[3]);
            $type = $this->phpConflictType($requested, $conflict);

            return $this->blocker($type, 'php', $requested, strtolower($matches[1]), $matches[2] !== '' ? $matches[2] : null, $conflict, [strtolower($matches[1]), 'php'], $evidenceId, 'high');
        }

        if (preg_match('~could not find(?: a matching version of)? package\s+(' . $package . ')~i', $output, $matches) === 1) {
            $subject = strtolower($matches[1]);

            return $this->blocker('package-not-found', $subject, $this->requestedConstraint($result, $subject), null, null, null, [$subject], $evidenceId, 'high');
        }

        if (preg_match(
            '~Root composer\.json requires\s+(' . $package . ')\s+([^\s,]+),\s+found\s+\1\[([^\]]+)]\s+but\s+(?:it does|these do) not match the constraint~i',
            $output,
            $matches
        ) === 1) {
            $subject = strtolower($matches[1]);
            $requestedConstraint = $this->requestedConstraint($result, $subject) ?? $this->cleanConstraint($matches[2]);

            return $this->blocker('package-not-found', $subject, $requestedConstraint, null, null, null, [$subject], $evidenceId, 'high');
        }

        if (stripos($output, 'minimum-stability') !== false) {
            $subject = $this->firstPackageTarget($result) ?? 'composer';

            return $this->blocker('minimum-stability-conflict', $subject, $this->requestedConstraint($result, $subject), null, null, 'minimum-stability', [$subject], $evidenceId, 'medium');
        }

        $relations = $this->relations($output);
        foreach ($relations as $relation) {
            if (in_array($relation['operation'], ['replaces', 'provides', 'conflicts with'], true)) {
                return $this->blocker('replace-provide-conflict', $relation['dependency'], $this->requestedConstraint($result, $relation['dependency']), $relation['package'], $relation['version'], $relation['constraint'], $this->dependencyPath($relations, $relation['dependency']), $evidenceId, 'high');
            }
        }

        if (preg_match('~(' . $package . ')\s+is locked to version\s+([^\s,]+)~i', $output, $matches) === 1
            || preg_match('~(' . $package . ')\s+is fixed to\s+([^\s,]+).*?lock file version~i', $output, $matches) === 1) {
            $blockingPackage = strtolower($matches[1]);
            $relation = $this->relationForDependency($relations, $blockingPackage);
            $subject = $this->onlyPackageTarget($result)
                ?? ($relation === null ? $blockingPackage : $relation['package']);
            $path = $relation === null
                ? array_values(array_unique([$subject, $blockingPackage]))
                : $this->dependencyPath($relations, $blockingPackage);

            return $this->blocker('transitive-package-conflict', $subject, $this->requestedConstraint($result, $subject), $blockingPackage, $matches[2], $relation === null ? null : $relation['constraint'], $path, $evidenceId, 'high');
        }

        if (preg_match('~Root composer\.json requires\s+(' . $platform . '|' . $package . ')\s+([^\s,;]+(?:\s*\|\|\s*[^\s,;]+)*)~i', $output, $matches) === 1) {
            $subject = strtolower($matches[1]);
            $rootConstraint = $this->cleanConstraint($matches[2]);
            $requestedConstraint = $this->requestedConstraint($result, $subject);

            if ($requestedConstraint !== null && $requestedConstraint !== $rootConstraint) {
                return $this->blocker('root-constraint-conflict', $subject, $requestedConstraint, null, null, $rootConstraint, [$subject], $evidenceId, 'high');
            }
        }

        if ($relations !== []) {
            $relation = $relations[0];
            $requested = $this->requestedConstraint($result, $relation['dependency']);
            $type = $this->relationType($relation['operation'], $relation['dependency'], $requested, $relation['constraint']);

            return $this->blocker($type, $relation['dependency'], $requested, $relation['package'], $relation['version'], $relation['constraint'], $this->dependencyPath($relations, $relation['dependency']), $evidenceId, 'high');
        }

        $subject = $this->firstPackageTarget($result) ?? ($result->scenario()->targets()->targetPhp() === null ? 'composer' : 'php');

        return $this->blocker('unknown-composer-failure', $subject, $this->requestedConstraint($result, $subject), null, null, null, [$subject], $evidenceId, 'low');
    }

    /** @return list<string> */
    private function problemSections(string $output): array
    {
        if (preg_match_all(
            '/(?:^|\R)\s*Problem\s+\d+\s*(.*?)(?=(?:\R\s*Problem\s+\d+\s*)|\z)/is',
            $output,
            $matches
        ) !== false && $matches[1] !== []) {
            return array_values(array_filter(array_map('trim', $matches[1]), static fn (string $problem): bool => $problem !== ''));
        }

        return [$output];
    }

    /**
     * @return list<array{package: string, version: ?string, operation: string, dependency: string, constraint: ?string}>
     */
    private function relations(string $output): array
    {
        $relations = [];
        $pattern = '~(' . self::PACKAGE_PATTERN . ')\s+([^\s]+)\s+(requires|conflicts with|replaces|provides)\s+(' . self::PLATFORM_PATTERN . '|' . self::PACKAGE_PATTERN . ')(?:\s+(.+))?~i';
        $treePattern = '~(' . self::PACKAGE_PATTERN . ')(?:\s+([^\s(]+))?\s+\((requires|conflicts with|replaces|provides)\s+(' . self::PLATFORM_PATTERN . '|' . self::PACKAGE_PATTERN . ')(?:\s+(.+))?\)\s*$~i';

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match($pattern, $line, $matches) !== 1
                && preg_match($treePattern, $line, $matches) !== 1) {
                continue;
            }

            $constraint = isset($matches[5]) ? preg_split('/\s+->\s+/', $matches[5], 2)[0] : null;
            $relations[] = [
                'package' => strtolower($matches[1]),
                'version' => $matches[2] === '' ? null : $matches[2],
                'operation' => strtolower($matches[3]),
                'dependency' => strtolower($matches[4]),
                'constraint' => $constraint === null ? null : $this->cleanConstraint($constraint),
            ];
        }

        return $relations;
    }

    /**
     * @param list<array{package: string, version: ?string, operation: string, dependency: string, constraint: ?string}> $relations
     * @return list<string>
     */
    private function dependencyPath(array $relations, string $subject): array
    {
        $path = [$subject];
        $current = $subject;

        while (true) {
            $parent = null;
            foreach ($relations as $relation) {
                if ($relation['dependency'] === $current && !in_array($relation['package'], $path, true)) {
                    $parent = $relation['package'];
                    break;
                }
            }
            if ($parent === null) {
                break;
            }
            array_unshift($path, $parent);
            $current = $parent;
        }

        return $path;
    }

    /**
     * @param list<array{package: string, version: ?string, operation: string, dependency: string, constraint: ?string}> $relations
     * @return null|array{package: string, version: ?string, operation: string, dependency: string, constraint: ?string}
     */
    private function relationForDependency(array $relations, string $dependency): ?array
    {
        foreach ($relations as $relation) {
            if ($relation['dependency'] === $dependency) {
                return $relation;
            }
        }

        return null;
    }

    private function relationType(string $operation, string $subject, ?string $requested, ?string $conflict): string
    {
        if (in_array($operation, ['replaces', 'provides', 'conflicts with'], true)) {
            return 'replace-provide-conflict';
        }
        if ($subject === 'php' || $subject === 'php-64bit') {
            return $this->phpConflictType($requested, $conflict);
        }
        if (strpos($subject, 'ext-') === 0) {
            return 'extension-missing';
        }

        return 'transitive-package-conflict';
    }

    private function phpConflictType(?string $requested, ?string $conflict): string
    {
        if ($requested === null || $conflict === null) {
            return 'php-platform-too-low';
        }

        try {
            $parser = new VersionParser();
            $required = $parser->parseConstraints($conflict);
            $target = $parser->normalize($requested);
            $allowsHigher = Intervals::haveIntersections($required, new Constraint('>', $target));
            $allowsLower = Intervals::haveIntersections($required, new Constraint('<', $target));

            return $allowsLower && !$allowsHigher ? 'php-platform-too-high' : 'php-platform-too-low';
        } catch (\Throwable) {
            return 'php-platform-too-low';
        }
    }

    private function requestedConstraint(ScenarioResult $result, string $subject): ?string
    {
        foreach ($result->scenario()->targets()->all() as $target) {
            if (strtolower($target->package()) === strtolower($subject)) {
                return $target->constraint();
            }
        }

        return null;
    }

    private function firstPackageTarget(ScenarioResult $result): ?string
    {
        $targets = $result->scenario()->targets()->packageTargets();

        return $targets === [] ? null : $targets[0]->package();
    }

    private function onlyPackageTarget(ScenarioResult $result): ?string
    {
        $targets = $result->scenario()->targets()->packageTargets();

        return count($targets) === 1 ? $targets[0]->package() : null;
    }

    private function cleanConstraint(string $constraint): ?string
    {
        $constraint = trim($constraint);
        $constraint = trim($constraint, " \t\n\r\0\x0B().,;");

        return $constraint === '' ? null : $constraint;
    }

    /** @param list<string> $dependencyPath */
    private function blocker(string $type, string $subject, ?string $requestedConstraint, ?string $blockingPackage, ?string $lockedVersion, ?string $conflict, array $dependencyPath, string $evidenceId, string $confidence): Blocker
    {
        return new Blocker($type, $subject, $this->summary($type), $confidence, [$evidenceId], $requestedConstraint, $blockingPackage, $lockedVersion, $conflict, $dependencyPath, $this->options($type, $subject, $blockingPackage));
    }

    private function summary(string $type): string
    {
        $summaries = [
            'php-platform-too-low' => 'The requested PHP platform is lower than a package requirement.',
            'php-platform-too-high' => 'The requested PHP platform is higher than a package supports.',
            'root-constraint-conflict' => 'A root Composer constraint conflicts with the requested target.',
            'transitive-package-conflict' => 'A transitive package constraint blocks the requested target.',
            'extension-missing' => 'A required PHP extension is unavailable.',
            'package-not-found' => 'Composer could not find the requested package or version.',
            'minimum-stability-conflict' => 'The requested package does not satisfy the project minimum stability.',
            'replace-provide-conflict' => 'Composer found conflicting replace, provide, or conflict rules.',
            'unknown-composer-failure' => 'Composer failed, but the blocker type could not be classified.',
        ];

        return $summaries[$type] ?? 'Composer reported a dependency blocker.';
    }

    /** @return list<string> */
    private function options(string $type, string $subject, ?string $blockingPackage): array
    {
        $blocker = $blockingPackage ?? 'the blocking package';
        $options = [
            'php-platform-too-low' => ['Raise the target PHP version.', sprintf('Select a version of `%s` compatible with the target PHP.', $blocker)],
            'php-platform-too-high' => [sprintf('Upgrade or replace `%s` with a version that supports the target PHP.', $blocker), 'Select a supported PHP target.'],
            'root-constraint-conflict' => [sprintf('Update the root constraint for `%s`.', $subject), 'Choose a target compatible with the existing root constraint.'],
            'transitive-package-conflict' => [sprintf('Upgrade or replace `%s`.', $blocker), sprintf('Choose a `%s` version compatible with the transitive constraint.', $subject)],
            'extension-missing' => [sprintf('Install and enable `%s` for the target runtime.', $subject), sprintf('Choose package versions that do not require `%s`.', $subject)],
            'package-not-found' => [sprintf('Verify the package name, constraint, and repositories for `%s`.', $subject), 'Choose an available package version.'],
            'minimum-stability-conflict' => ['Choose a release allowed by the project minimum stability.', 'Explicitly allow the required stability only after reviewing the package.'],
            'replace-provide-conflict' => [sprintf('Remove or replace `%s`.', $blocker), 'Choose versions whose replace/provide rules can coexist.'],
            'unknown-composer-failure' => ['Inspect the linked Composer evidence.', sprintf('Run `composer prohibits %s <constraint> --tree` in an isolated copy.', $subject)],
        ];

        return $options[$type] ?? ['Inspect the linked Composer evidence.'];
    }
}
