<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ComposerLock
{
    /** @var array<string, mixed> */
    private array $data;
    /** @var array<string, PackageRef> */
    private array $packages;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->packages = $this->indexPackages($data);
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

    /** @param array<string, mixed> $data @return array<string, PackageRef> */
    private function indexPackages(array $data): array
    {
        $indexed = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $data[$section] ?? [];
            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $package) {
                if (!is_array($package) || !isset($package['name'], $package['version'])) {
                    continue;
                }

                $name = strtolower((string) $package['name']);
                $indexed[$name] = new PackageRef($name, (string) $package['version']);
            }
        }

        return $indexed;
    }
}
