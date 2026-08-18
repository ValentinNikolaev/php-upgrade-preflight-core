<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ComposerJson
{
    /** @var array<string, mixed> */
    private array $data;
    /** @var array<string, string|false> */
    private array $platformPackages;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->platformPackages = self::normalizePlatformPackages($data['config']['platform'] ?? null);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string, string> */
    public function rootRequirements(): array
    {
        return array_merge(
            $this->stringMap($this->data['require'] ?? []),
            $this->stringMap($this->data['require-dev'] ?? [])
        );
    }

    public function platformPhp(): ?string
    {
        $platform = $this->configuredPlatformPackages()['php'] ?? null;

        return is_string($platform) ? $platform : null;
    }

    /** @return array<string, string|false> */
    public function configuredPlatformPackages(): array
    {
        return $this->platformPackages;
    }

    public function packageName(): ?string
    {
        $name = $this->data['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? strtolower(trim($name)) : null;
    }

    /** @return array<string, mixed> */
    public function autoload(): array
    {
        return $this->arrayValue($this->data['autoload'] ?? null);
    }

    /** @return array<string, mixed> */
    public function autoloadDev(): array
    {
        return $this->arrayValue($this->data['autoload-dev'] ?? null);
    }

    public function vendorDirectory(): string
    {
        $vendorDirectory = $this->data['config']['vendor-dir'] ?? null;

        return is_string($vendorDirectory) && trim($vendorDirectory) !== ''
            ? trim($vendorDirectory)
            : 'vendor';
    }

    /** @return list<array{name: string, state: string, version: ?string, provenance: string}> */
    public function configuredExtensions(): array
    {
        $extensions = [];
        foreach ($this->configuredPlatformPackages() as $name => $value) {
            if (str_starts_with($name, 'ext-')) {
                $extensions[] = [
                    'name' => $name,
                    'state' => $value === false ? 'absent' : 'present',
                    'version' => is_string($value) ? $value : null,
                    'provenance' => 'composer_config',
                ];
            }
        }

        usort($extensions, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $extensions;
    }

    /**
     * Normalize once at construction so an invalid manifest cannot exist and no query method has
     * to validate lazily.
     *
     * @param mixed $platform
     * @return array<string, string|false>
     */
    private static function normalizePlatformPackages(mixed $platform): array
    {
        if (!is_array($platform)) {
            return [];
        }

        $packages = [];
        foreach ($platform as $name => $value) {
            if (!is_string($name) || (!is_string($value) && $value !== false)) {
                continue;
            }

            $name = strtolower(trim($name));
            if (array_key_exists($name, $packages)) {
                if ($packages[$name] !== $value) {
                    throw new \InvalidArgumentException(
                        'Project config.platform contains contradictory duplicate package names.'
                    );
                }

                continue;
            }

            $packages[$name] = $value;
        }
        ksort($packages, SORT_STRING);

        return $packages;
    }

    /** @param mixed $value @return array<string, string> */
    private function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $name => $constraint) {
            if (is_string($name) && is_string($constraint)) {
                $map[strtolower($name)] = $constraint;
            }
        }

        return $map;
    }

    /** @param mixed $value @return array<string, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
