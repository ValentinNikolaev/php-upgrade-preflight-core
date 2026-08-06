<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class LockDiff
{
    /** @var list<PackageChange> */
    private array $packageChanges;

    /** @param list<PackageChange> $packageChanges */
    public function __construct(array $packageChanges)
    {
        $this->packageChanges = array_values($packageChanges);
    }

    /** @return list<PackageChange> */
    public function packageChanges(): array
    {
        return $this->packageChanges;
    }

    /** @return array{package_changes: list<array{name: string, change_type: string, from_version: ?string, to_version: ?string, major_change: bool}>} */
    public function toArray(): array
    {
        return [
            'package_changes' => array_map(static fn (PackageChange $change): array => $change->toArray(), $this->packageChanges),
        ];
    }
}
