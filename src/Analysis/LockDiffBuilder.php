<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\PackageRef;

final class LockDiffBuilder
{
    public function build(ComposerLock $before, ComposerLock $after): LockDiff
    {
        $changes = [];
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
                    $to->distReference()
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
                    null
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
                    $this->majorVersion($from->version()) !== $this->majorVersion($to->version()),
                    $from->sourceReference(),
                    $to->sourceReference(),
                    $from->distReference(),
                    $to->distReference()
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
}
