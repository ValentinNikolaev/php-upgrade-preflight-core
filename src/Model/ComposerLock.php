<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

use PhpUpgradePreflight\Core\Support\SensitiveOutputRedactor;

final class ComposerLock
{
    private const MAX_REPORTED_UNUSABLE_PACKAGES = 10;

    /** @var array<string, mixed> */
    private array $data;
    /** @var array<string, PackageRef> */
    private array $packages;
    /** @var list<string> */
    private array $unusablePackageNames = [];
    /** @var list<string> */
    private array $versionlessPackageNames = [];

    /**
     * @param array<string, mixed> $data
     * @param list<string> $directPackageNames
     */
    public function __construct(array $data, array $directPackageNames = [])
    {
        $this->data = $data;
        $this->packages = $this->indexPackages($data, $directPackageNames);
    }

    /**
     * Lock entries this analyzer could not index, as evidence-safe uncertainty sentences.
     *
     * A lockfile the tool cannot fully read is still a valid input that must produce a canonical
     * report, so an entry whose name violates Composer's grammar, or which carries no readable
     * version to index it by, is skipped rather than fatal. Skipping it silently would under-report
     * locked packages, package changes, abandoned-package advisories, framework findings read from
     * the entry's own requirements, and autoload ownership with no visible reason, so each omission
     * is published instead.
     *
     * @return list<string>
     */
    public function unusablePackageUncertainties(): array
    {
        return $this->skippedEntryUncertainties('Composer lock');
    }

    /**
     * The same omissions worded for a candidate lockfile a Composer scenario produced.
     *
     * A candidate lock is not the analyzed project's own input, and it is discarded with its
     * workspace: the entries it could not index shrink the published candidate package count and the
     * package changes derived from it, while the recorded hash still covers the whole file. That is a
     * different omission from the project lock's, so it gets its own sentence instead of being
     * collapsed into it.
     *
     * @return list<string>
     */
    public function unusableCandidatePackageUncertainties(): array
    {
        return $this->skippedEntryUncertainties('Composer candidate lock');
    }

    /**
     * @param string $lockSubject names the lockfile whose entries were skipped
     * @return list<string>
     */
    private function skippedEntryUncertainties(string $lockSubject): array
    {
        $uncertainties = [];

        if ($this->unusablePackageNames !== []) {
            $uncertainties[] = sprintf(
                '%s entries were skipped because their package names are not valid Composer '
                    . 'package names, so locked packages, package changes, and autoload ownership may be '
                    . 'incomplete: %s.',
                $lockSubject,
                $this->reportableNames($this->unusablePackageNames)
            );
        }

        if ($this->versionlessPackageNames !== []) {
            $uncertainties[] = sprintf(
                '%s entries were skipped because they carry no readable version, so locked packages, '
                    . 'package changes, and the framework findings derived from their own requirements '
                    . 'may be incomplete: %s.',
                $lockSubject,
                $this->reportableNames($this->versionlessPackageNames)
            );
        }

        return $uncertainties;
    }

    /**
     * Renders skipped package names as one evidence-safe, bounded clause.
     *
     * @param list<string> $names
     */
    private function reportableNames(array $names): string
    {
        // The names came from an untrusted lockfile and are republished here, so they pass through
        // the same redaction every other model-ingress value does. A name repeated across `packages`
        // and `packages-dev` is one unusable package, and the clause is bounded so a pathological
        // lock cannot inflate the report.
        $names = array_values(array_unique($names));
        $listed = array_slice($names, 0, self::MAX_REPORTED_UNUSABLE_PACKAGES);
        $remaining = count($names) - count($listed);

        return implode(', ', array_map(
            static fn (string $name): string => SensitiveOutputRedactor::redact(mb_substr($name, 0, 120)),
            $listed
        )) . ($remaining > 0 ? sprintf(' (and %d more)', $remaining) : '');
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string, PackageRef> */
    public function packages(): array
    {
        return $this->packages;
    }

    public function package(string $name): ?PackageRef
    {
        return $this->packages[strtolower($name)] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $directPackageNames
     * @return array<string, PackageRef>
     */
    private function indexPackages(array $data, array $directPackageNames): array
    {
        $directPackages = [];
        foreach ($directPackageNames as $name) {
            $directPackages[strtolower($name)] = true;
        }

        $indexed = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $data[$section] ?? [];
            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $package) {
                if (!is_array($package) || !isset($package['name']) || !is_scalar($package['name'])) {
                    continue;
                }

                $name = strtolower((string) $package['name']);
                if (!PackageRef::isValidName($name)) {
                    $this->unusablePackageNames[] = $name;
                    continue;
                }

                // Composer always writes a version, so an entry without one is untrusted input that
                // cannot become a PackageRef. It is recorded rather than dropped, because callers
                // reading this index would otherwise lose the package with no visible reason.
                if (!isset($package['version'])
                    || !is_string($package['version'])
                    || trim($package['version']) === '') {
                    $this->versionlessPackageNames[] = $name;
                    continue;
                }

                $abandonedAlternative = $this->abandonedAlternative($package);
                $indexed[$name] = new PackageRef(
                    $name,
                    (string) $package['version'],
                    isset($directPackages[$name]),
                    $this->packageReference($package, 'source'),
                    $this->packageReference($package, 'dist'),
                    ($package['abandoned'] ?? false) === true || $abandonedAlternative !== null,
                    $abandonedAlternative,
                    $this->autoloadMetadata($package, 'autoload'),
                    $this->packageRequirements($package)
                );
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $package
     * @return array<array-key, mixed>
     */
    private function packageRequirements(array $package): array
    {
        $requirements = $package['require'] ?? null;

        return is_array($requirements) ? $requirements : [];
    }

    /** @param array<string, mixed> $package @return array<string, mixed> */
    private function autoloadMetadata(array $package, string $key): array
    {
        $autoload = $package[$key] ?? null;

        return is_array($autoload) ? $autoload : [];
    }

    /** @param array<string, mixed> $package */
    private function packageReference(array $package, string $key): ?string
    {
        $metadata = $package[$key] ?? null;
        if (!is_array($metadata)) {
            return null;
        }

        $reference = $metadata['reference'] ?? null;

        return is_string($reference) ? $reference : null;
    }

    /** @param array<string, mixed> $package */
    private function abandonedAlternative(array $package): ?string
    {
        $replacement = $package['abandoned'] ?? null;
        if (!is_string($replacement)) {
            return null;
        }

        $replacement = trim($replacement);

        return $replacement === '' ? null : $replacement;
    }
}
