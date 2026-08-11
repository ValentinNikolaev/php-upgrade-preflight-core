<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

final class SymbolOwnershipIndex
{
    public const ROOT_OWNER = '@root';

    /** @var array<string, array<string, array<string, array<string, true>>>> */
    private array $exact = [];
    /** @var array<string, array<string, array<string, true>>> */
    private array $prefixes = [];
    private ?string $rootPackageName;

    public function __construct(?string $rootPackageName = null)
    {
        $this->rootPackageName = $rootPackageName;
    }

    public function addExact(string $symbol, string $owner, string $mappingType, string $symbolType = 'class'): void
    {
        $symbol = $this->canonicalSymbol($symbol);
        if ($symbol === '') {
            return;
        }
        if (!in_array($symbolType, ['class', 'function', 'constant'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported exact symbol type "%s".', $symbolType));
        }

        $key = $symbolType === 'constant' ? $symbol : strtolower($symbol);
        $this->exact[$symbolType][$key][$owner][$mappingType] = true;
    }

    public function addPrefix(string $prefix, string $owner, string $mappingType): void
    {
        $this->prefixes[strtolower(ltrim($prefix, '\\'))][$owner][$mappingType] = true;
    }

    /**
     * @return array{owners: list<string>, mapping_types: list<string>, matched_prefix: ?string}
     */
    public function lookup(string $symbol, bool $includePrefixes = true, string $symbolType = 'class'): array
    {
        if (!in_array($symbolType, ['class', 'function', 'constant'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported lookup symbol type "%s".', $symbolType));
        }

        $symbol = $this->canonicalSymbol($symbol);
        if ($symbol === '') {
            return ['owners' => [], 'mapping_types' => [], 'matched_prefix' => null];
        }

        $exactKey = $symbolType === 'constant' ? $symbol : strtolower($symbol);
        if (isset($this->exact[$symbolType][$exactKey])) {
            return $this->result($this->exact[$symbolType][$exactKey], null);
        }

        if (!$includePrefixes) {
            return ['owners' => [], 'mapping_types' => [], 'matched_prefix' => null];
        }

        $symbol = strtolower($symbol);
        $longest = -1;
        /** @var array<string, array<string, true>> $matches */
        $matches = [];
        $matchedPrefix = null;
        foreach ($this->prefixes as $prefix => $owners) {
            if (!str_starts_with($symbol, $prefix)) {
                continue;
            }

            $length = strlen($prefix);
            if ($length < $longest) {
                continue;
            }
            if ($length > $longest) {
                $matches = [];
                $longest = $length;
                $matchedPrefix = $prefix;
            }

            foreach ($owners as $owner => $mappingTypes) {
                foreach ($mappingTypes as $mappingType => $_) {
                    $matches[$owner][$mappingType] = true;
                }
            }
        }

        return $this->result($matches, $matchedPrefix);
    }

    public function displayOwner(string $owner): ?string
    {
        return $owner === self::ROOT_OWNER ? $this->rootPackageName : $owner;
    }

    public function describeOwner(string $owner): string
    {
        return $owner === self::ROOT_OWNER
            ? ($this->rootPackageName ?? 'the root project')
            : $owner;
    }

    /**
     * @param array<string, array<string, true>> $owners
     * @return array{owners: list<string>, mapping_types: list<string>, matched_prefix: ?string}
     */
    private function result(array $owners, ?string $matchedPrefix): array
    {
        $ownerNames = array_keys($owners);
        sort($ownerNames, SORT_STRING);
        $mappingTypes = [];
        foreach ($owners as $types) {
            foreach (array_keys($types) as $type) {
                $mappingTypes[$type] = true;
            }
        }
        $mappingTypes = array_keys($mappingTypes);
        sort($mappingTypes, SORT_STRING);

        return [
            'owners' => $ownerNames,
            'mapping_types' => $mappingTypes,
            'matched_prefix' => $matchedPrefix,
        ];
    }

    private function canonicalSymbol(string $symbol): string
    {
        return ltrim(trim($symbol), '\\');
    }
}
