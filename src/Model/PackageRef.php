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
    /** @var array<string, string> */
    private array $requirements;

    /** @param array<array-key, mixed> $requirements the package's own Composer `require` block */
    public function __construct(
        string $name,
        string $version,
        bool $direct = false,
        ?string $sourceReference = null,
        ?string $distReference = null,
        bool $abandoned = false,
        ?string $abandonedAlternative = null,
        array $autoload = [],
        array $requirements = []
    ) {
        $name = strtolower($name);
        if (preg_match(self::PACKAGE_NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid Composer package name "%s".', $name));
        }

        $this->name = $name;
        $this->version = $version;
        $this->direct = $direct;
        $this->sourceReference = $sourceReference;
        $this->distReference = $distReference;
        $abandonedAlternative = $abandonedAlternative === null ? null : trim($abandonedAlternative);
        $this->abandonedAlternative = $abandonedAlternative === '' ? null : $abandonedAlternative;
        $this->abandoned = $abandoned || $this->abandonedAlternative !== null;
        $this->autoload = $autoload;

        $normalizedRequirements = [];
        foreach ($requirements as $requiredName => $constraint) {
            if (is_string($constraint)) {
                $normalizedRequirements[strtolower((string) $requiredName)] = $constraint;
            }
        }
        $this->requirements = $normalizedRequirements;
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

    /**
     * Reports whether a name satisfies Composer's package-name grammar.
     *
     * Callers reading untrusted project input should use this to skip unusable lock entries
     * instead of constructing a PackageRef and catching the resulting exception, so a malformed
     * lockfile still yields a canonical report rather than an aborted analysis.
     */
    public static function isValidName(string $name): bool
    {
        return preg_match(self::PACKAGE_NAME_PATTERN, strtolower($name)) === 1;
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

    /**
     * The package's own Composer requirements, keyed by lowercased package name.
     *
     * Callers asking what a locked package depends on read it here instead of
     * re-walking the raw `packages`/`packages-dev` sections of the lock document.
     * Non-string constraint values are dropped, so every value is a constraint
     * string a caller can hand to a SemVer parser.
     *
     * @return array<string, string>
     */
    public function requirements(): array
    {
        return $this->requirements;
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
