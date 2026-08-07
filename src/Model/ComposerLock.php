<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ComposerLock
{
    /** @var array<string, mixed> */
    private array $data;
    /** @var array<string, PackageRef> */
    private array $packages;

    /**
     * @param array<string, mixed> $data
     * @param list<string> $directPackageNames
     */
    public function __construct(array $data, array $directPackageNames = [])
    {
        $this->data = $data;
        $this->packages = $this->indexPackages($data, $directPackageNames);
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
                if (!is_array($package) || !isset($package['name'], $package['version'])) {
                    continue;
                }

                $name = strtolower((string) $package['name']);
                $abandonedAlternative = $this->abandonedAlternative($package);
                $indexed[$name] = new PackageRef(
                    $name,
                    (string) $package['version'],
                    isset($directPackages[$name]),
                    $this->packageReference($package, 'source'),
                    $this->packageReference($package, 'dist'),
                    ($package['abandoned'] ?? false) === true || $abandonedAlternative !== null,
                    $abandonedAlternative
                );
            }
        }

        return $indexed;
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
