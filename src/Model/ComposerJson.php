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
