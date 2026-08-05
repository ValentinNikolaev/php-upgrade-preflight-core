<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class PackageChange
{
    public string $name;
    public string $changeType;
    public ?string $fromVersion;
    public ?string $toVersion;
    public bool $majorChange;

    public function __construct(string $name, string $changeType, ?string $fromVersion, ?string $toVersion, bool $majorChange = false)
    {
        $this->name = $name;
        $this->changeType = $changeType;
        $this->fromVersion = $fromVersion;
        $this->toVersion = $toVersion;
        $this->majorChange = $majorChange;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'change_type' => $this->changeType,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'major_change' => $this->majorChange,
        ];
    }
}
