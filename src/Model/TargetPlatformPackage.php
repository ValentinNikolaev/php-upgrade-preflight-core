<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class TargetPlatformPackage
{
    public const CLASS_PHP = 'php';
    public const CLASS_EXTENSION = 'extension';
    public const CLASS_LIBRARY = 'library';
    public const CLASS_PHP_SUBTYPE = 'php_subtype';
    public const CLASS_COMPOSER_PLATFORM = 'composer_platform';

    public const STATE_PRESENT = 'present';
    public const STATE_ABSENT = 'absent';

    public const PROVENANCE_REQUEST = 'request';
    public const PROVENANCE_COMPOSER_CONFIG = 'composer_config';
    public const PROVENANCE_PROFILE = 'profile';
    public const PROVENANCE_CLOSED_WORLD = 'closed_world';

    public const SIMULATION_COMPOSER_CONFIG = 'composer_config';
    public const SIMULATION_TOOLCHAIN_BOUND = 'toolchain_bound';

    /** @var list<string> */
    private const PHP_SUBTYPES = ['php-64bit', 'php-debug', 'php-ipv6', 'php-zts'];
    /** @var list<string> */
    private const COMPOSER_PLATFORM_PACKAGES = ['composer', 'composer-plugin-api', 'composer-runtime-api'];

    private string $name;
    private string $class;
    private string $state;
    private ?string $version;
    private string $provenance;
    private string $simulation;

    public static function fromPresenceOnlyExtension(
        string $name,
        string $provenance = self::PROVENANCE_REQUEST
    ): self {
        $package = new self($name, '0', $provenance);
        if ($package->class !== self::CLASS_EXTENSION) {
            throw new \InvalidArgumentException('Only an extension can be modeled as present without an exact version.');
        }

        $package->version = null;

        return $package;
    }

    /** @param string|false $value */
    public function __construct(string $name, $value, string $provenance = self::PROVENANCE_PROFILE)
    {
        $name = strtolower(trim($name));
        $class = self::classify($name);

        if (!is_string($value) && $value !== false) {
            throw new \InvalidArgumentException('Target platform package value must be an exact version string or false.');
        }

        if ($name === 'php' && $value === false) {
            throw new \InvalidArgumentException('The PHP target platform package cannot be absent.');
        }

        if (!in_array($provenance, self::supportedProvenance(), true)) {
            throw new \InvalidArgumentException('Target platform package provenance is unsupported.');
        }

        $this->name = $name;
        $this->class = $class;
        $this->state = $value === false ? self::STATE_ABSENT : self::STATE_PRESENT;
        $this->version = $value === false ? null : self::normalizeVersion($name, $value);
        $this->provenance = $provenance;
        $this->simulation = $class === self::CLASS_COMPOSER_PLATFORM
            ? self::SIMULATION_TOOLCHAIN_BOUND
            : self::SIMULATION_COMPOSER_CONFIG;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function class(): string
    {
        return $this->class;
    }

    public function packageClass(): string
    {
        return $this->class;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function provenance(): string
    {
        return $this->provenance;
    }

    public function simulation(): string
    {
        return $this->simulation;
    }

    public function isAbsent(): bool
    {
        return $this->state === self::STATE_ABSENT;
    }

    public function isPresentWithoutVersion(): bool
    {
        return $this->state === self::STATE_PRESENT && $this->version === null;
    }

    public function isToolchainBound(): bool
    {
        return $this->simulation === self::SIMULATION_TOOLCHAIN_BOUND;
    }

    /** @return list<string> */
    public static function toolchainBoundNames(): array
    {
        return self::COMPOSER_PLATFORM_PACKAGES;
    }

    public static function isSupportedName(string $name): bool
    {
        $name = strtolower(trim($name));

        return $name === 'php'
            || in_array($name, self::PHP_SUBTYPES, true)
            || in_array($name, self::COMPOSER_PLATFORM_PACKAGES, true)
            || preg_match('/^(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*$/D', $name) === 1;
    }

    /** @return string|false */
    public function composerValue()
    {
        return $this->isAbsent() ? false : ($this->version ?? '0');
    }

    /** @return array{name: string, class: string, state: string, version: ?string, provenance: string, simulation: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'class' => $this->class,
            'state' => $this->state,
            'version' => $this->version,
            'provenance' => $this->provenance,
            'simulation' => $this->simulation,
        ];
    }

    private static function classify(string $name): string
    {
        if ($name === 'php') {
            return self::CLASS_PHP;
        }
        if (in_array($name, self::PHP_SUBTYPES, true)) {
            return self::CLASS_PHP_SUBTYPE;
        }
        if (in_array($name, self::COMPOSER_PLATFORM_PACKAGES, true)) {
            return self::CLASS_COMPOSER_PLATFORM;
        }
        if (preg_match('/^ext-[a-z0-9](?:[_.-]?[a-z0-9]+)*$/D', $name) === 1) {
            return self::CLASS_EXTENSION;
        }
        if (preg_match('/^lib-[a-z0-9](?:[_.-]?[a-z0-9]+)*$/D', $name) === 1) {
            return self::CLASS_LIBRARY;
        }

        throw new \InvalidArgumentException('Target platform package name is unsupported.');
    }

    private static function normalizeVersion(string $name, string $version): string
    {
        $version = trim($version);

        if ($name === 'php') {
            if (preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/iD', $version, $matches) !== 1) {
                throw new \InvalidArgumentException('Target platform PHP must be an exact numeric version.');
            }

            return sprintf(
                '%d.%d.%d',
                (int) $matches[1],
                isset($matches[2]) ? (int) $matches[2] : 0,
                isset($matches[3]) ? (int) $matches[3] : 0
            );
        }

        if (preg_match('/^v?\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D', $version) !== 1) {
            throw new \InvalidArgumentException('Target platform package must use an exact version, not a constraint.');
        }

        return $version;
    }

    /** @return list<string> */
    private static function supportedProvenance(): array
    {
        return [
            self::PROVENANCE_REQUEST,
            self::PROVENANCE_COMPOSER_CONFIG,
            self::PROVENANCE_PROFILE,
            self::PROVENANCE_CLOSED_WORLD,
        ];
    }
}
