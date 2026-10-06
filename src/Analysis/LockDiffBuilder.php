<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Framework\PackageFamilyClassifier;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\PackageRef;

final class LockDiffBuilder
{
    /**
     * @param list<PackageFamilyClassifier> $familyClassifiers
     * @param list<string> $uncertainties
     */
    public function build(
        ComposerLock $before,
        ComposerLock $after,
        array $familyClassifiers = [],
        ?EvidenceLedger $evidence = null,
        array &$uncertainties = []
    ): LockDiff {
        $changes = [];
        $failedClassifiers = [];
        $beforePackages = $before->packages();
        $afterPackages = $after->packages();
        $names = array_unique(array_merge(array_keys($beforePackages), array_keys($afterPackages)));
        sort($names);

        foreach ($names as $name) {
            $from = $beforePackages[$name] ?? null;
            $to = $afterPackages[$name] ?? null;

            if ($from === null && $to !== null) {
                $changes[] = new PackageChange(
                    $name,
                    'added',
                    null,
                    $to->version(),
                    false,
                    null,
                    $to->sourceReference(),
                    null,
                    $to->distReference(),
                    $to->isDirect(),
                    $this->packageFamilies($name, $familyClassifiers, $evidence, $uncertainties, $failedClassifiers)
                );
                continue;
            }

            if ($from !== null && $to === null) {
                $changes[] = new PackageChange(
                    $name,
                    'removed',
                    $from->version(),
                    null,
                    false,
                    $from->sourceReference(),
                    null,
                    $from->distReference(),
                    null,
                    $from->isDirect(),
                    $this->packageFamilies($name, $familyClassifiers, $evidence, $uncertainties, $failedClassifiers)
                );
                continue;
            }

            if ($from instanceof PackageRef && $to instanceof PackageRef && $this->packageChanged($from, $to)) {
                $changes[] = new PackageChange(
                    $name,
                    $from->version() === $to->version()
                        ? 'changed'
                        : $this->compareVersions($from->version(), $to->version()),
                    $from->version(),
                    $to->version(),
                    $this->isMajorVersionChange($from->version(), $to->version()),
                    $from->sourceReference(),
                    $to->sourceReference(),
                    $from->distReference(),
                    $to->distReference(),
                    $to->isDirect(),
                    $this->packageFamilies($name, $familyClassifiers, $evidence, $uncertainties, $failedClassifiers)
                );
            }
        }

        return new LockDiff($changes);
    }

    private function packageChanged(PackageRef $from, PackageRef $to): bool
    {
        return $from->version() !== $to->version()
            || $from->sourceReference() !== $to->sourceReference()
            || $from->distReference() !== $to->distReference();
    }

    private function compareVersions(string $from, string $to): string
    {
        $normalizedFrom = ltrim($from, 'v');
        $normalizedTo = ltrim($to, 'v');

        if (version_compare($normalizedFrom, $normalizedTo, '<')) {
            return 'upgraded';
        }

        if (version_compare($normalizedFrom, $normalizedTo, '>')) {
            return 'downgraded';
        }

        return 'changed';
    }

    private function majorVersion(string $version): ?int
    {
        if (preg_match('/^v?(\\d+)/', $version, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function isMajorVersionChange(string $from, string $to): bool
    {
        $fromMajor = $this->majorVersion($from);
        $toMajor = $this->majorVersion($to);

        return $fromMajor !== null && $toMajor !== null && $fromMajor !== $toMajor;
    }

    /**
     * @param list<PackageFamilyClassifier> $classifiers
     * @param list<string> $uncertainties
     * @param array<int, true> $failedClassifiers
     * @return list<string>
     */
    private function packageFamilies(
        string $packageName,
        array $classifiers,
        ?EvidenceLedger $evidence,
        array &$uncertainties,
        array &$failedClassifiers
    ): array {
        $families = [];

        foreach ($classifiers as $classifier) {
            $classifierId = spl_object_id($classifier);
            if (isset($failedClassifiers[$classifierId])) {
                continue;
            }

            try {
                $classifiedFamilies = [];
                foreach ($classifier->packageFamilies($packageName) as $family) {
                    $family = trim($family);
                    if ($family !== '') {
                        $classifiedFamilies[$family] = true;
                    }
                }
                $families += $classifiedFamilies;
            } catch (\Throwable $exception) {
                $failedClassifiers[$classifierId] = true;
                $references = [];
                if ($evidence !== null) {
                    $references[] = $evidence->add(
                        'framework-adapter',
                        Evidence::E2_PACKAGE_METADATA,
                        'A framework adapter package-family classifier failed.',
                        'high',
                        [
                            'classifier' => get_class($classifier),
                            'package' => $packageName,
                            'reason' => 'package_family_failure',
                            'error' => $exception->getMessage(),
                        ]
                    )->id();
                }
                $uncertainties[] = sprintf(
                    'Package-family classifier "%s" failed for "%s", so its package families are missing from this diff%s.',
                    get_class($classifier),
                    $packageName,
                    $references === [] ? '' : ' (' . implode(', ', $references) . ')'
                );
            }
        }

        $families = array_keys($families);
        sort($families);

        return $families;
    }
}
