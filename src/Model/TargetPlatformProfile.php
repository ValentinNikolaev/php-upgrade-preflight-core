<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

use PhpUpgradePreflight\Core\Composer\TargetPlatformProfileFileReader;

final class TargetPlatformProfile
{
    public const SCHEMA_VERSION = '1.0';
    public const COMPLETENESS_PARTIAL = 'partial';
    public const COMPLETENESS_COMPLETE = 'complete';
    public const PROVENANCE_PHP_API = 'php_api';
    public const PROVENANCE_FILE = 'file';

    /** @var list<string> */
    private const SUPPORTED_CLASSES = [
        TargetPlatformPackage::CLASS_PHP,
        TargetPlatformPackage::CLASS_EXTENSION,
        TargetPlatformPackage::CLASS_LIBRARY,
        TargetPlatformPackage::CLASS_PHP_SUBTYPE,
        TargetPlatformPackage::CLASS_COMPOSER_PLATFORM,
    ];

    private string $schemaVersion;
    private string $completeness;
    /** @var array<string, TargetPlatformPackage> */
    private array $packages;
    private string $digest;
    private string $provenance;

    /** @param list<TargetPlatformPackage> $packages */
    public function __construct(
        string $completeness,
        array $packages,
        string $provenance = self::PROVENANCE_PHP_API,
        string $schemaVersion = self::SCHEMA_VERSION
    ) {
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('Target platform profile schema version is unsupported.');
        }
        if (!in_array($completeness, [self::COMPLETENESS_PARTIAL, self::COMPLETENESS_COMPLETE], true)) {
            throw new \InvalidArgumentException('Target platform profile completeness is unsupported.');
        }
        if (!in_array($provenance, [self::PROVENANCE_PHP_API, self::PROVENANCE_FILE], true)) {
            throw new \InvalidArgumentException('Target platform profile provenance is unsupported.');
        }

        $indexed = [];
        foreach ($packages as $index => $package) {
            if (!$package instanceof TargetPlatformPackage) {
                throw new \InvalidArgumentException(sprintf(
                    'Target platform package at index %d must be a TargetPlatformPackage.',
                    $index
                ));
            }

            if ($package->isPresentWithoutVersion()) {
                throw new \InvalidArgumentException(
                    'Target platform profile packages must use an exact version or false.'
                );
            }

            $canonicalPackage = new TargetPlatformPackage(
                $package->name(),
                $package->composerValue(),
                TargetPlatformPackage::PROVENANCE_PROFILE
            );
            $name = $canonicalPackage->name();
            if (isset($indexed[$name])) {
                if ($indexed[$name]->composerValue() !== $canonicalPackage->composerValue()) {
                    throw new \InvalidArgumentException('Target platform profile contains contradictory duplicate packages.');
                }

                continue;
            }

            $indexed[$name] = $canonicalPackage;
        }
        ksort($indexed, SORT_STRING);

        if ($completeness === self::COMPLETENESS_COMPLETE && !isset($indexed['php'])) {
            throw new \InvalidArgumentException('A complete target platform profile must include an exact PHP version.');
        }

        $this->schemaVersion = $schemaVersion;
        $this->completeness = $completeness;
        $this->packages = $indexed;
        $this->provenance = $provenance;
        $this->digest = $this->calculateDigest();
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data, string $provenance = self::PROVENANCE_PHP_API): self
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['completeness', 'packages', 'schema_version']) {
            throw new \InvalidArgumentException('Target platform profile must contain exactly schema_version, completeness, and packages.');
        }
        if (!is_string($data['schema_version']) || !is_string($data['completeness']) || !is_array($data['packages'])) {
            throw new \InvalidArgumentException('Target platform profile has invalid field types.');
        }

        $packages = [];
        foreach ($data['packages'] as $name => $value) {
            if (!is_string($name) || (!is_string($value) && $value !== false)) {
                throw new \InvalidArgumentException('Target platform profile packages must map names to exact versions or false.');
            }

            $packages[] = new TargetPlatformPackage($name, $value, TargetPlatformPackage::PROVENANCE_PROFILE);
        }

        return new self($data['completeness'], $packages, $provenance, $data['schema_version']);
    }

    public static function fromJson(string $json, string $provenance = self::PROVENANCE_FILE): self
    {
        try {
            $decoded = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Target platform profile contains invalid JSON.', 0, $exception);
        }

        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('Target platform profile JSON must contain an object.');
        }

        self::assertUniqueJsonObjectKeys($json);

        $data = get_object_vars($decoded);
        if (property_exists($decoded, 'packages')) {
            if (!$decoded->packages instanceof \stdClass) {
                throw new \InvalidArgumentException('Target platform profile has invalid field types.');
            }

            $data['packages'] = get_object_vars($decoded->packages);
        }

        return self::fromArray($data, $provenance);
    }

    /**
     * @deprecated Filesystem access moved to TargetPlatformProfileFileReader; call that reader instead.
     * @see \PhpUpgradePreflight\Core\Composer\TargetPlatformProfileFileReader::read()
     */
    public static function fromFile(string $path): self
    {
        return (new TargetPlatformProfileFileReader())->read($path);
    }

    public function schemaVersion(): string
    {
        return $this->schemaVersion;
    }

    public function completeness(): string
    {
        return $this->completeness;
    }

    public function isComplete(): bool
    {
        return $this->completeness === self::COMPLETENESS_COMPLETE;
    }

    /** @return list<TargetPlatformPackage> */
    public function packages(): array
    {
        return array_values($this->packages);
    }

    public function package(string $name): ?TargetPlatformPackage
    {
        return $this->packages[strtolower(trim($name))] ?? null;
    }

    public function digest(): string
    {
        return $this->digest;
    }

    public function sha256(): string
    {
        return $this->digest;
    }

    public function provenance(): string
    {
        return $this->provenance;
    }

    /** @return list<string> */
    public function supportedClasses(): array
    {
        return self::SUPPORTED_CLASSES;
    }

    /** @return list<string> */
    public function toolchainBoundPackages(): array
    {
        return TargetPlatformPackage::toolchainBoundNames();
    }

    /** @return array<string, string|false> */
    public function composerPlatformOverrides(): array
    {
        $overrides = [];
        foreach ($this->packages as $package) {
            if (!$package->isToolchainBound()) {
                $overrides[$package->name()] = $package->composerValue();
            }
        }

        return $overrides;
    }

    /** @return array{schema_version: string, completeness: string, sha256: string, provenance: string} */
    public function summary(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'completeness' => $this->completeness,
            'sha256' => $this->digest,
            'provenance' => $this->provenance,
        ];
    }

    /**
     * @return array{
     *   schema_version: string,
     *   completeness: string,
     *   sha256: string,
     *   provenance: string,
     *   supported_classes: list<string>,
     *   closed_world: bool,
     *   toolchain_bound: list<string>,
     *   effective: list<array{name: string, class: string, state: string, version: ?string, provenance: string, simulation: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'completeness' => $this->completeness,
            'sha256' => $this->digest,
            'provenance' => $this->provenance,
            'supported_classes' => self::SUPPORTED_CLASSES,
            'closed_world' => $this->isComplete(),
            'toolchain_bound' => $this->toolchainBoundPackages(),
            'effective' => array_map(
                static fn (TargetPlatformPackage $package): array => $package->toArray(),
                $this->packages()
            ),
        ];
    }

    private function calculateDigest(): string
    {
        $values = [];
        foreach ($this->packages as $package) {
            $values[$package->name()] = $package->composerValue();
        }

        $encoded = json_encode([
            'schema_version' => $this->schemaVersion,
            'completeness' => $this->completeness,
            'packages' => $values,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
    }

    private static function assertUniqueJsonObjectKeys(string $json): void
    {
        $offset = 0;
        self::scanJsonValue($json, $offset);
    }

    /** @return mixed */
    private static function scanJsonValue(string $json, int &$offset)
    {
        self::skipJsonWhitespace($json, $offset);
        $token = $json[$offset] ?? '';

        if ($token === '{') {
            return self::scanJsonObject($json, $offset);
        }
        if ($token === '[') {
            return self::scanJsonArray($json, $offset);
        }
        if ($token === '"') {
            return self::scanJsonString($json, $offset);
        }

        $start = $offset;
        $length = strlen($json);
        while ($offset < $length && !in_array($json[$offset], [',', ']', '}'], true)) {
            ++$offset;
        }

        return json_decode(trim(substr($json, $start, $offset - $start)), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function scanJsonObject(string $json, int &$offset): array
    {
        ++$offset;
        self::skipJsonWhitespace($json, $offset);
        $values = [];
        if (($json[$offset] ?? '') === '}') {
            ++$offset;

            return $values;
        }

        while (true) {
            $key = self::scanJsonString($json, $offset);
            if (array_key_exists($key, $values)) {
                throw new \InvalidArgumentException(
                    'Target platform profile contains duplicate JSON object keys.'
                );
            }

            self::skipJsonWhitespace($json, $offset);
            ++$offset;
            $values[$key] = self::scanJsonValue($json, $offset);
            self::skipJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') === '}') {
                ++$offset;

                return $values;
            }

            ++$offset;
            self::skipJsonWhitespace($json, $offset);
        }
    }

    /** @return list<mixed> */
    private static function scanJsonArray(string $json, int &$offset): array
    {
        ++$offset;
        self::skipJsonWhitespace($json, $offset);
        $values = [];
        if (($json[$offset] ?? '') === ']') {
            ++$offset;

            return $values;
        }

        while (true) {
            $values[] = self::scanJsonValue($json, $offset);
            self::skipJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') === ']') {
                ++$offset;

                return $values;
            }

            ++$offset;
            self::skipJsonWhitespace($json, $offset);
        }
    }

    private static function scanJsonString(string $json, int &$offset): string
    {
        $start = $offset;
        ++$offset;
        $length = strlen($json);
        while ($offset < $length) {
            if ($json[$offset] === '\\') {
                $offset += 2;
                continue;
            }
            if ($json[$offset] === '"') {
                ++$offset;
                break;
            }
            ++$offset;
        }

        $value = json_decode(substr($json, $start, $offset - $start), true, 64, JSON_THROW_ON_ERROR);

        return is_string($value) ? $value : '';
    }

    private static function skipJsonWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            ++$offset;
        }
    }
}
