<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class HostExtension
{
    private string $name;
    private ?string $version;

    public function __construct(string $name, ?string $version)
    {
        $name = str_replace(' ', '-', strtolower(trim($name)));
        if (preg_match('/^[a-z0-9_.-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid host extension name "%s".', $name));
        }

        $this->name = 'ext-' . $name;
        $this->version = $version === null || trim($version) === '' ? null : trim($version);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): ?string
    {
        return $this->version;
    }
}
