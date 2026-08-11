<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class PackageRef
{
    private const PACKAGE_NAME_PATTERN = '~^[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*$~iD';

    private string $name;
    private string $version;
    private bool $direct;
    private ?string $sourceReference;
    private ?string $distReference;
    private bool $abandoned;
    private ?string $abandonedAlternative;
    /** @var array<string, mixed> */
    private array $autoload;

    public function __construct(
        string $name,
        string $version,
        bool $direct = false,
        ?string $sourceReference = null,
        ?string $distReference = null,
        bool $abandoned = false,
        ?string $abandonedAlternative = null,
        array $autoload = []
    ) {
        $this->name = strtolower($name);
        $this->version = $version;
        $this->direct = $direct;
        $this->sourceReference = $sourceReference;
        $this->distReference = $distReference;
        $abandonedAlternative = $abandonedAlternative === null ? null : trim($abandonedAlternative);
        $this->abandonedAlternative = $abandonedAlternative === '' ? null : $abandonedAlternative;
        $this->abandoned = $abandoned || $this->abandonedAlternative !== null;
        $this->autoload = $autoload;
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

    public function isAbandoned(): bool
    {
        return $this->abandoned;
    }

    public function replacementPackage(): ?string
    {
        if ($this->abandonedAlternative === null
            || preg_match(self::PACKAGE_NAME_PATTERN, $this->abandonedAlternative) !== 1) {
            return null;
        }

        return strtolower($this->abandonedAlternative);
    }

    public function abandonedAlternative(): ?string
    {
        return $this->abandonedAlternative;
    }

    public function abandonedAlternativeType(): ?string
    {
        if ($this->replacementPackage() !== null) {
            return 'package';
        }

        if ($this->abandonedAlternative === null) {
            return null;
        }

        return filter_var($this->abandonedAlternative, FILTER_VALIDATE_URL) === false ? 'other' : 'url';
    }

    /** @return array<string, mixed> */
    public function autoload(): array
    {
        return $this->autoload;
    }

    /** @return array{name: string, version: string, direct: bool, source_reference: ?string, dist_reference: ?string, abandoned: bool, abandoned_alternative: ?string, abandoned_alternative_type: ?string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'direct' => $this->direct,
            'source_reference' => $this->sourceReference,
            'dist_reference' => $this->distReference,
            'abandoned' => $this->abandoned,
            'abandoned_alternative' => $this->abandonedAlternative,
            'abandoned_alternative_type' => $this->abandonedAlternativeType(),
        ];
    }
}
