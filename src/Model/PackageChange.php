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
    private ?string $fromSourceReference;
    private ?string $toSourceReference;
    private ?string $fromDistReference;
    private ?string $toDistReference;
    private bool $direct;
    /** @var list<string> */
    private array $packageFamilies;

    /** @param list<string> $packageFamilies */
    public function __construct(
        string $name,
        string $changeType,
        ?string $fromVersion,
        ?string $toVersion,
        bool $majorChange = false,
        ?string $fromSourceReference = null,
        ?string $toSourceReference = null,
        ?string $fromDistReference = null,
        ?string $toDistReference = null,
        bool $direct = false,
        array $packageFamilies = []
    ) {
        $this->name = $name;
        $this->changeType = $changeType;
        $this->fromVersion = $fromVersion;
        $this->toVersion = $toVersion;
        $this->majorChange = $majorChange;
        $this->fromSourceReference = $fromSourceReference;
        $this->toSourceReference = $toSourceReference;
        $this->fromDistReference = $fromDistReference;
        $this->toDistReference = $toDistReference;
        $this->direct = $direct;
        $families = [];
        foreach ($packageFamilies as $family) {
            $family = trim($family);
            if ($family !== '') {
                $families[$family] = true;
            }
        }
        $this->packageFamilies = array_keys($families);
        sort($this->packageFamilies);
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

    public function isDirect(): bool
    {
        return $this->direct;
    }

    /** @return list<string> */
    public function packageFamilies(): array
    {
        return $this->packageFamilies;
    }

    public function fromSourceReference(): ?string
    {
        return $this->fromSourceReference;
    }

    public function toSourceReference(): ?string
    {
        return $this->toSourceReference;
    }

    public function fromDistReference(): ?string
    {
        return $this->fromDistReference;
    }

    public function toDistReference(): ?string
    {
        return $this->toDistReference;
    }

    public function hasSourceReferenceChange(): bool
    {
        return $this->fromSourceReference !== $this->toSourceReference;
    }

    public function hasDistReferenceChange(): bool
    {
        return $this->fromDistReference !== $this->toDistReference;
    }

    /** @return array{name: string, change_type: string, from_version: ?string, to_version: ?string, direct: bool, major_change: bool, package_families: list<string>, from_source_reference: ?string, to_source_reference: ?string, from_dist_reference: ?string, to_dist_reference: ?string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'change_type' => $this->changeType,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'direct' => $this->direct,
            'major_change' => $this->majorChange,
            'package_families' => $this->packageFamilies,
            'from_source_reference' => $this->fromSourceReference,
            'to_source_reference' => $this->toSourceReference,
            'from_dist_reference' => $this->fromDistReference,
            'to_dist_reference' => $this->toDistReference,
        ];
    }
}
