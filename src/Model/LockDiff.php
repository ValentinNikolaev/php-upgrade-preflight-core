<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class LockDiff
{
    /** @var list<PackageChange> */
    public array $packageChanges;

    /** @param list<PackageChange> $packageChanges */
    public function __construct(array $packageChanges)
    {
        $this->packageChanges = array_values($packageChanges);
    }

    public function toArray(): array
    {
        return [
            'package_changes' => array_map(static fn (PackageChange $change): array => $change->toArray(), $this->packageChanges),
        ];
    }
}
