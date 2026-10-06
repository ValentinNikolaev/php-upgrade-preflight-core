<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\BlockerAttribution;
use PhpUpgradePreflight\Core\Model\BlockerType;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ExtensionAssumption;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SolverRelation;
use PhpUpgradePreflight\Core\Model\TargetPlatform;

final class ComposerBlockerParser
{
    private const PACKAGE_PATTERN = '[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*';
    private const PLATFORM_PATTERN = '(?:php(?:-64bit|-ipv6)?|ext-[a-z0-9_.-]+|lib-[a-z0-9_.-]+|composer(?:-plugin-api|-runtime-api)?)';

    /** @return list<Blocker> */
    public function parse(ScenarioResult $result, string $evidenceId, ?TargetPlatform $platform = null): array
    {
        $blockers = [];

        foreach ($result->diagnostics() as $diagnostic) {
            foreach ($this->fromDiagnostic($result, $diagnostic, $evidenceId, $platform) as $blocker) {
                $blockers[] = $blocker;
            }
        }

        if ($blockers !== []) {
            return $blockers;
        }

        $output = trim($result->stdout() . "\n" . $result->stderr());

        return array_map(
            fn (string $problem): Blocker => $this->fromOutput($problem, $result, $evidenceId, $platform),
            $this->problemSections($output)
        );
    }

    /** @return list<Blocker> */
    private function fromDiagnostic(
        ScenarioResult $result,
        ComposerDiagnostic $diagnostic,
        string $evidenceId,
        ?TargetPlatform $platform
    ): array {
        $output = trim($diagnostic->stdout() . "\n" . $diagnostic->stderr());
        if ($output === '') {
            return [];
        }

        $relations = $this->relations($output);
        if ($relations === []) {
            return [];
        }

        $matchingRelations = $this->relationsBlaming($result, $relations, strtolower($diagnostic->package()));
        if ($matchingRelations === []) {
            return [];
        }

        $blockers = [];
        $seen = [];
        foreach ($matchingRelations as $relation) {
            $identity = $this->relationIdentity($relation);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $type = $this->relationType($relation, $diagnostic->constraint(), $platform);
            $blockers[] = $this->blocker(
                $type,
                $relation->dependency(),
                $diagnostic->constraint(),
                BlockerAttribution::fromRelation($relation),
                $this->dependencyPath($relations, $relation->dependency()),
                $evidenceId,
                $this->confidenceForType($type)
            );
        }

        return $blockers;
    }

    /**
     * Relations that may be blamed for the diagnostic subject: every relation pointing at
     * the subject, otherwise the first relation not explicitly included in the update.
     *
     * @param list<SolverRelation> $relations
     * @return list<SolverRelation>
     */
    private function relationsBlaming(ScenarioResult $result, array $relations, string $subject): array
    {
        $matchingRelations = [];
        foreach ($relations as $candidate) {
            if ($candidate->dependency() === $subject
                && !$this->isExplicitlyUpdatedBlockingPackage($result, $candidate->package(), $subject)) {
                $matchingRelations[] = $candidate;
            }
        }
        if ($matchingRelations !== []) {
            return $matchingRelations;
        }

        foreach ($relations as $candidate) {
            if (!$this->isExplicitlyUpdatedBlockingPackage($result, $candidate->package(), $subject)) {
                return [$candidate];
            }
        }

        return [];
    }

    /** Platform relations deduplicate on the requirement alone; package relations also on the requiring package. */
    private function relationIdentity(SolverRelation $relation): string
    {
        if (preg_match('~^' . self::PLATFORM_PATTERN . '$~i', $relation->dependency()) === 1) {
            return serialize([$relation->dependency(), $relation->operation(), $relation->constraint()]);
        }

        return serialize([
            $relation->dependency(),
            $relation->package(),
            $relation->version(),
            $relation->operation(),
            $relation->constraint(),
        ]);
    }

    private function isExplicitlyUpdatedBlockingPackage(
        ScenarioResult $result,
        string $blockingPackage,
        string $subject
    ): bool {
        if ($blockingPackage === $subject) {
            return false;
        }

        foreach ($result->scenario()->targets()->packageTargets() as $target) {
            if ($target->package() === $blockingPackage) {
                return true;
            }
        }

        return false;
    }

    private function fromOutput(
        string $output,
        ScenarioResult $result,
        string $evidenceId,
        ?TargetPlatform $targetPlatform
    ): Blocker {
        $relations = $this->relations($output);

        // Ordered from most to least specific. The first matcher that recognises the
        // output wins, and the unclassified fallback always produces a blocker.
        return $this->abandonedPackageBlocker($output, $result, $evidenceId)
            ?? $this->disabledRootExtensionBlocker($output, $result, $evidenceId, $targetPlatform)
            ?? $this->unavailableExtensionBlocker($output, $result, $evidenceId, $targetPlatform)
            ?? $this->phpRequirementBlocker($output, $result, $evidenceId)
            ?? $this->missingPackageBlocker($output, $result, $evidenceId)
            ?? $this->unsatisfiedRootRequirementBlocker($output, $result, $evidenceId)
            ?? $this->minimumStabilityBlocker($output, $result, $evidenceId)
            ?? $this->replaceProvideBlocker($result, $evidenceId, $relations)
            ?? $this->lockedPackageBlocker($output, $result, $evidenceId, $relations)
            ?? $this->rootConstraintBlocker($output, $result, $evidenceId)
            ?? $this->leadingRelationBlocker($result, $evidenceId, $targetPlatform, $relations)
            ?? $this->unclassifiedFailureBlocker($result, $evidenceId);
    }

    private function abandonedPackageBlocker(string $output, ScenarioResult $result, string $evidenceId): ?Blocker
    {
        $package = self::PACKAGE_PATTERN;

        if (preg_match('~Package\s+(' . $package . ')\s+is abandoned(?:, you should avoid using it)?(?:\. Use\s+(' . $package . ')\s+instead)?~i', $output, $matches) === 1) {
            $subject = strtolower($matches[1]);
            $options = isset($matches[2])
                ? [sprintf('Replace `%s` with `%s`.', $subject, strtolower($matches[2]))]
                : [sprintf('Replace `%s` with a maintained alternative.', $subject)];

            return new Blocker(
                BlockerType::ABANDONED_PACKAGE,
                $subject,
                'Composer reported an abandoned package.',
                'high',
                [$evidenceId],
                $this->requestedConstraint($result, $subject),
                null,
                null,
                null,
                [$subject],
                $options
            );
        }

        return null;
    }

    private function disabledRootExtensionBlocker(
        string $output,
        ScenarioResult $result,
        string $evidenceId,
        ?TargetPlatform $targetPlatform
    ): ?Blocker {
        if (preg_match('~Root composer\.json requires(?: PHP extension)?\s+(ext-[a-z0-9_.-]+)\s+([^\s,;)]+).*?disabled by your platform config~is', $output, $matches) === 1) {
            $subject = strtolower($matches[1]);
            $type = $this->extensionType($subject, $targetPlatform);

            return $this->blocker(
                $type,
                $subject,
                $this->requestedConstraint($result, $subject),
                BlockerAttribution::forConstraint($this->cleanConstraint($matches[2])),
                [$subject],
                $evidenceId,
                $this->confidenceForType($type)
            );
        }

        return null;
    }

    private function unavailableExtensionBlocker(
        string $output,
        ScenarioResult $result,
        string $evidenceId,
        ?TargetPlatform $targetPlatform
    ): ?Blocker {
        $package = self::PACKAGE_PATTERN;

        if (preg_match('~(?:(' . $package . ')\s+([^\s]+)\s+requires\s+)?(ext-[a-z0-9_.-]+)\s+([^\s,;)]+).*?(?:missing from your system|is missing|disabled by your platform config)~is', $output, $matches) === 1) {
            $subject = strtolower($matches[3]);
            $blockingPackage = $matches[1] !== '' ? strtolower($matches[1]) : null;
            $type = $this->extensionType($subject, $targetPlatform);

            return $this->blocker(
                $type,
                $subject,
                $this->requestedConstraint($result, $subject),
                new BlockerAttribution(
                    $blockingPackage,
                    $matches[2] !== '' ? $matches[2] : null,
                    $this->cleanConstraint($matches[4])
                ),
                $blockingPackage === null ? [$subject] : [$blockingPackage, $subject],
                $evidenceId,
                $this->confidenceForType($type)
            );
        }

        return null;
    }

    private function phpRequirementBlocker(string $output, ScenarioResult $result, string $evidenceId): ?Blocker
    {
        $package = self::PACKAGE_PATTERN;

        if (preg_match('~(' . $package . ')(?:\s+([^\s]+))?\s+requires\s+php(?:-64bit)?\s+(.+?)(?=\s+->|\R|$)~i', $output, $matches) === 1) {
            $requested = $this->requestedConstraint($result, 'php');
            if ($requested === null && preg_match('/your php version \((?:[^0-9]*)(\d+(?:\.\d+){1,3})/i', $output, $version) === 1) {
                $requested = $version[1];
            }
            $conflict = $this->cleanConstraint($matches[3]);
            $blockingPackage = strtolower($matches[1]);

            return $this->blocker(
                $this->phpConflictType($requested, $conflict),
                'php',
                $requested,
                new BlockerAttribution($blockingPackage, $matches[2] !== '' ? $matches[2] : null, $conflict),
                [$blockingPackage, 'php'],
                $evidenceId,
                'high'
            );
        }

        return null;
    }

    private function missingPackageBlocker(string $output, ScenarioResult $result, string $evidenceId): ?Blocker
    {
        $package = self::PACKAGE_PATTERN;

        if (preg_match('~could not find(?: a matching version of)? package\s+(' . $package . ')~i', $output, $matches) === 1) {
            $subject = strtolower($matches[1]);

            return $this->blocker(
                BlockerType::PACKAGE_NOT_FOUND,
                $subject,
                $this->requestedConstraint($result, $subject),
                BlockerAttribution::none(),
                [$subject],
                $evidenceId,
                'high'
            );
        }

        return null;
    }

    private function unsatisfiedRootRequirementBlocker(
        string $output,
        ScenarioResult $result,
        string $evidenceId
    ): ?Blocker {
        $package = self::PACKAGE_PATTERN;

        if (preg_match(
            '~Root composer\.json requires\s+(' . $package . ')\s+([^\s,]+),\s+found\s+\1\[([^\]]+)]\s+but\s+(?:it does|these do) not match the constraint~i',
            $output,
            $matches
        ) === 1) {
            $subject = strtolower($matches[1]);

            return $this->blocker(
                BlockerType::PACKAGE_NOT_FOUND,
                $subject,
                $this->requestedConstraint($result, $subject) ?? $this->cleanConstraint($matches[2]),
                BlockerAttribution::none(),
                [$subject],
                $evidenceId,
                'high'
            );
        }

        return null;
    }

    private function minimumStabilityBlocker(string $output, ScenarioResult $result, string $evidenceId): ?Blocker
    {
        if (stripos($output, 'minimum-stability') === false) {
            return null;
        }

        $subject = $this->firstPackageTarget($result) ?? 'composer';

        return $this->blocker(
            BlockerType::MINIMUM_STABILITY_CONFLICT,
            $subject,
            $this->requestedConstraint($result, $subject),
            BlockerAttribution::forConstraint('minimum-stability'),
            [$subject],
            $evidenceId,
            'medium'
        );
    }

    /** @param list<SolverRelation> $relations */
    private function replaceProvideBlocker(ScenarioResult $result, string $evidenceId, array $relations): ?Blocker
    {
        foreach ($relations as $relation) {
            if (!$relation->isIncompatibilityRule()) {
                continue;
            }

            return $this->blocker(
                BlockerType::REPLACE_PROVIDE_CONFLICT,
                $relation->dependency(),
                $this->requestedConstraint($result, $relation->dependency()),
                BlockerAttribution::fromRelation($relation),
                $this->dependencyPath($relations, $relation->dependency()),
                $evidenceId,
                'high'
            );
        }

        return null;
    }

    /** @param list<SolverRelation> $relations */
    private function lockedPackageBlocker(
        string $output,
        ScenarioResult $result,
        string $evidenceId,
        array $relations
    ): ?Blocker {
        $package = self::PACKAGE_PATTERN;

        if (preg_match('~(' . $package . ')\s+is locked to version\s+([^\s,]+)~i', $output, $matches) === 1
            || preg_match('~(' . $package . ')\s+is fixed to\s+([^\s,]+).*?lock file version~i', $output, $matches) === 1) {
            $blockingPackage = strtolower($matches[1]);
            $relation = $this->relationForDependency($relations, $blockingPackage);
            $subject = $this->onlyPackageTarget($result)
                ?? ($relation === null ? $blockingPackage : $relation->package());
            $path = $relation === null
                ? array_values(array_unique([$subject, $blockingPackage]))
                : $this->dependencyPath($relations, $blockingPackage);

            return $this->blocker(
                BlockerType::TRANSITIVE_PACKAGE_CONFLICT,
                $subject,
                $this->requestedConstraint($result, $subject),
                new BlockerAttribution(
                    $blockingPackage,
                    $matches[2],
                    $relation === null ? null : $relation->constraint()
                ),
                $path,
                $evidenceId,
                'high'
            );
        }

        return null;
    }

    private function rootConstraintBlocker(string $output, ScenarioResult $result, string $evidenceId): ?Blocker
    {
        $package = self::PACKAGE_PATTERN;
        $platform = self::PLATFORM_PATTERN;

        if (preg_match('~Root composer\.json requires\s+(' . $platform . '|' . $package . ')\s+([^\s,;]+(?:\s*\|\|\s*[^\s,;]+)*)~i', $output, $matches) === 1) {
            $subject = strtolower($matches[1]);
            $rootConstraint = $this->cleanConstraint($matches[2]);
            $requestedConstraint = $this->requestedConstraint($result, $subject);

            if ($requestedConstraint !== null && $requestedConstraint !== $rootConstraint) {
                return $this->blocker(
                    BlockerType::ROOT_CONSTRAINT_CONFLICT,
                    $subject,
                    $requestedConstraint,
                    BlockerAttribution::forConstraint($rootConstraint),
                    [$subject],
                    $evidenceId,
                    'high'
                );
            }
        }

        return null;
    }

    /** @param list<SolverRelation> $relations */
    private function leadingRelationBlocker(
        ScenarioResult $result,
        string $evidenceId,
        ?TargetPlatform $targetPlatform,
        array $relations
    ): ?Blocker {
        if ($relations === []) {
            return null;
        }

        $relation = $relations[0];
        $requested = $this->requestedConstraint($result, $relation->dependency());
        $type = $this->relationType($relation, $requested, $targetPlatform);

        return $this->blocker(
            $type,
            $relation->dependency(),
            $requested,
            BlockerAttribution::fromRelation($relation),
            $this->dependencyPath($relations, $relation->dependency()),
            $evidenceId,
            $this->confidenceForType($type)
        );
    }

    private function unclassifiedFailureBlocker(ScenarioResult $result, string $evidenceId): Blocker
    {
        $subject = $this->firstPackageTarget($result)
            ?? ($result->scenario()->targets()->targetPhp() === null ? 'composer' : 'php');

        return $this->blocker(
            BlockerType::UNKNOWN_COMPOSER_FAILURE,
            $subject,
            $this->requestedConstraint($result, $subject),
            BlockerAttribution::none(),
            [$subject],
            $evidenceId,
            'low'
        );
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

    /** @return list<SolverRelation> */
    private function relations(string $output): array
    {
        $relations = [];
        $pattern = '~(' . self::PACKAGE_PATTERN . ')\s+([^\s]+)\s+(requires|conflicts with|replaces|provides)\s+(' . self::PLATFORM_PATTERN . '|' . self::PACKAGE_PATTERN . ')(?:\s+(.+))?~i';
        $treePattern = '~(' . self::PACKAGE_PATTERN . ')(?:\s+([^\s(]+))?\s+\((requires|conflicts(?: with)?|replaces|provides)\s+(' . self::PLATFORM_PATTERN . '|' . self::PACKAGE_PATTERN . ')(?:\s+(.+?))?\)\s*(?:\(circular dependency aborted here\))?\s*$~i';

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match($pattern, $line, $matches) !== 1
                && preg_match($treePattern, $line, $matches) !== 1) {
                continue;
            }

            $constraintParts = isset($matches[5]) ? preg_split('/\s+->\s+/', $matches[5], 2) : false;
            $constraint = $constraintParts === false ? null : $constraintParts[0];
            $operation = strtolower($matches[3]);
            $relations[] = new SolverRelation(
                strtolower($matches[1]),
                $matches[2] === '' ? null : $matches[2],
                $operation === 'conflicts' ? SolverRelation::CONFLICTS_WITH : $operation,
                strtolower($matches[4]),
                $constraint === null ? null : $this->cleanConstraint($constraint)
            );
        }

        return $relations;
    }

    /**
     * @param list<SolverRelation> $relations
     * @return list<string>
     */
    private function dependencyPath(array $relations, string $subject): array
    {
        $path = [$subject];
        $current = $subject;

        while (true) {
            $parent = null;
            foreach ($relations as $relation) {
                if ($relation->dependency() === $current && !in_array($relation->package(), $path, true)) {
                    $parent = $relation->package();
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

    /** @param list<SolverRelation> $relations */
    private function relationForDependency(array $relations, string $dependency): ?SolverRelation
    {
        foreach ($relations as $relation) {
            if ($relation->dependency() === $dependency) {
                return $relation;
            }
        }

        return null;
    }

    private function relationType(
        SolverRelation $relation,
        ?string $requested,
        ?TargetPlatform $platform = null
    ): string {
        if ($relation->isIncompatibilityRule()) {
            return BlockerType::REPLACE_PROVIDE_CONFLICT;
        }

        $subject = $relation->dependency();
        if ($subject === 'php' || $subject === 'php-64bit') {
            return $this->phpConflictType($requested, $relation->constraint());
        }
        if (strpos($subject, 'ext-') === 0) {
            return $this->extensionType($subject, $platform);
        }

        return BlockerType::TRANSITIVE_PACKAGE_CONFLICT;
    }

    private function phpConflictType(?string $requested, ?string $conflict): string
    {
        if ($requested === null || $conflict === null) {
            return BlockerType::PHP_PLATFORM_TOO_LOW;
        }

        try {
            $parser = new VersionParser();
            $required = $parser->parseConstraints($conflict);
            $target = $parser->normalize($requested);
            $allowsHigher = Intervals::haveIntersections($required, new Constraint('>', $target));
            $allowsLower = Intervals::haveIntersections($required, new Constraint('<', $target));

            return $allowsLower && !$allowsHigher
                ? BlockerType::PHP_PLATFORM_TOO_HIGH
                : BlockerType::PHP_PLATFORM_TOO_LOW;
        } catch (\Throwable) {
            return BlockerType::PHP_PLATFORM_TOO_LOW;
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
        $constraint = preg_replace('/\s+but it is missing$/i', '', $constraint) ?? $constraint;
        $constraint = trim($constraint, " \t\n\r\0\x0B().,;");

        return $constraint === '' ? null : $constraint;
    }

    /** @param list<string> $dependencyPath */
    private function blocker(
        string $type,
        string $subject,
        ?string $requestedConstraint,
        BlockerAttribution $attribution,
        array $dependencyPath,
        string $evidenceId,
        string $confidence
    ): Blocker {
        $blockerType = BlockerType::fromString($type);

        return new Blocker(
            $type,
            $subject,
            $blockerType->summary(),
            $confidence,
            [$evidenceId],
            $requestedConstraint,
            $attribution->blockingPackage(),
            $attribution->lockedVersion(),
            $attribution->conflict(),
            $dependencyPath,
            $blockerType->options($subject, $attribution->blockingPackage())
        );
    }

    /** An assumed-but-unversioned extension is weaker evidence than a hard solver conflict. */
    private function confidenceForType(string $type): string
    {
        return $type === BlockerType::EXTENSION_VERSION_UNKNOWN ? 'medium' : 'high';
    }

    private function extensionType(string $subject, ?TargetPlatform $platform): string
    {
        if ($platform === null) {
            return BlockerType::EXTENSION_MISSING;
        }

        $assumption = $platform->extensionAssumption($subject);
        if ($assumption === null || $assumption->state() === ExtensionAssumption::ABSENT) {
            return BlockerType::EXTENSION_MISSING;
        }

        return $assumption->isPresentWithoutVersion()
            ? BlockerType::EXTENSION_VERSION_UNKNOWN
            : BlockerType::EXTENSION_VERSION_INCOMPATIBLE;
    }
}
