<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class PackageRef
{
    private string $name;
    private string $version;
    private bool $direct;

    public function __construct(string $name, string $version, bool $direct = false)
    {
        $this->name = strtolower($name);
        $this->version = $version;
        $this->direct = $direct;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isDirect(): bool
    {
        return $this->direct;
    }

    /** @return array{name: string, version: string, direct: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'direct' => $this->direct,
        ];
    }
}
