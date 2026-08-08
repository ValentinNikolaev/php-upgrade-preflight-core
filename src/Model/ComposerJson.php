<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ComposerJson
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
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
        $platform = $this->data['config']['platform']['php'] ?? null;

        return is_string($platform) ? $platform : null;
    }

    /** @return list<array{name: string, state: string, version: ?string, provenance: string}> */
    public function configuredExtensions(): array
    {
        $platform = $this->data['config']['platform'] ?? null;
        if (!is_array($platform)) {
            return [];
        }

        $extensions = [];
        foreach ($platform as $name => $value) {
            if (is_string($name) && str_starts_with(strtolower($name), 'ext-') && (is_string($value) || $value === false)) {
                $extensions[] = [
                    'name' => strtolower($name),
                    'state' => $value === false ? 'absent' : 'present',
                    'version' => is_string($value) ? $value : null,
                    'provenance' => 'composer_config',
                ];
            }
        }

        usort($extensions, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $extensions;
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
}
