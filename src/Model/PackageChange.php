<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class PackageChange
{
    private string $name;
    private string $changeType;
    private ?string $fromVersion;
    private ?string $toVersion;
    private bool $majorChange;

    public function __construct(string $name, string $changeType, ?string $fromVersion, ?string $toVersion, bool $majorChange = false)
    {
        $this->name = $name;
        $this->changeType = $changeType;
        $this->fromVersion = $fromVersion;
        $this->toVersion = $toVersion;
        $this->majorChange = $majorChange;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function changeType(): string
    {
        return $this->changeType;
    }

    public function fromVersion(): ?string
    {
        return $this->fromVersion;
    }

    public function toVersion(): ?string
    {
        return $this->toVersion;
    }

    public function isMajorChange(): bool
    {
        return $this->majorChange;
    }

    /** @return array{name: string, change_type: string, from_version: ?string, to_version: ?string, major_change: bool} */
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
