<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class PackageRef
{
    public string $name;
    public string $version;
    public bool $direct;

    public function __construct(string $name, string $version, bool $direct = false)
    {
        $this->name = strtolower($name);
        $this->version = $version;
        $this->direct = $direct;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'direct' => $this->direct,
        ];
    }
}
