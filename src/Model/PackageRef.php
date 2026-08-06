<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class PackageRef
{
    private string $name;
    private string $version;
    private bool $direct;
    private ?string $sourceReference;
    private ?string $distReference;

    public function __construct(
        string $name,
        string $version,
        bool $direct = false,
        ?string $sourceReference = null,
        ?string $distReference = null
    ) {
        $this->name = strtolower($name);
        $this->version = $version;
        $this->direct = $direct;
        $this->sourceReference = $sourceReference;
        $this->distReference = $distReference;
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

    public function sourceReference(): ?string
    {
        return $this->sourceReference;
    }

    public function distReference(): ?string
    {
        return $this->distReference;
    }

    /** @return array{name: string, version: string, direct: bool, source_reference: ?string, dist_reference: ?string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'direct' => $this->direct,
            'source_reference' => $this->sourceReference,
            'dist_reference' => $this->distReference,
        ];
    }
}
