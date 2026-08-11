<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\SourceImpactFinding;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Source\SymbolOwnershipIndex;

final class SourceImpactBuilder
{
    /**
     * @param list<SourceUsage> $inventory
     * @param list<CompatibilityFinding> $frameworkFindings
     * @param list<PackageChange> $packageChanges
     * @return list<SourceImpactFinding>
     */
    public function build(
        array $inventory,
        array $frameworkFindings,
        array $packageChanges = [],
        ?SymbolOwnershipIndex $ownershipIndex = null,
        ?EvidenceLedger $evidence = null
    ): array {
        $impact = [];
        $impactIndexes = [];
        $relevantChanges = $this->relevantPackageChanges($packageChanges);

        foreach ($inventory as $usage) {
            $matchingFindings = array_values(array_filter(
                $frameworkFindings,
                static fn (CompatibilityFinding $finding): bool => array_intersect(
                    $usage->evidence(),
                    $finding->evidence()
                ) !== []
            ));
            $ownership = $ownershipIndex === null || !$this->isSymbolUsage($usage)
                ? ['owners' => [], 'mapping_types' => [], 'matched_prefix' => null]
                : $ownershipIndex->lookup(
                    $usage->symbol(),
                    $this->supportsPrefixOwnership($usage),
                    $this->symbolType($usage)
                );

            $changedOwners = [];
            foreach ($ownership['owners'] as $owner) {
                if (isset($relevantChanges[$owner])) {
                    $changedOwners[$owner] = $relevantChanges[$owner];
                }
            }

            if ($matchingFindings === [] && $changedOwners === []) {
                continue;
            }

            if ($changedOwners !== []) {
                foreach ($changedOwners as $owner => $change) {
                    $impact = $this->appendFinding($impact, $impactIndexes, $this->finding(
                        $usage,
                        $matchingFindings,
                        $change,
                        $owner,
                        $ownership,
                        $ownershipIndex,
                        $evidence
                    ));
                }
                continue;
            }

            $impact = $this->appendFinding($impact, $impactIndexes, $this->finding(
                $usage,
                $matchingFindings,
                null,
                count($ownership['owners']) === 1 ? $ownership['owners'][0] : null,
                $ownership,
                $ownershipIndex,
                $evidence
            ));
        }

        return $impact;
    }

    /**
     * @param list<SourceImpactFinding> $impact
     * @param array<string, int> $indexes
     * @return non-empty-list<SourceImpactFinding>
     */
    private function appendFinding(array $impact, array &$indexes, SourceImpactFinding $finding): array
    {
        $first = $finding->occurrences()[0];
        $key = serialize([
            $finding->affectedPackage(),
            $finding->ownership(),
            $finding->relevance(),
            $finding->reason(),
            $finding->severity(),
            $first->symbol(),
            $first->usageType(),
        ]);

        if (!isset($indexes[$key])) {
            $indexes[$key] = count($impact);
            $impact[] = $finding;

            return $impact;
        }

        $index = $indexes[$key];
        $existing = $impact[$index];
        $occurrences = $existing->occurrences();
        $occurrenceIndexes = [];
        foreach ($occurrences as $occurrenceIndex => $occurrence) {
            $occurrenceIndexes[$this->occurrenceKey($occurrence)] = $occurrenceIndex;
        }
        foreach ($finding->occurrences() as $occurrence) {
            $occurrenceKey = $this->occurrenceKey($occurrence);
            if (isset($occurrenceIndexes[$occurrenceKey])) {
                $occurrenceIndex = $occurrenceIndexes[$occurrenceKey];
                $occurrences[$occurrenceIndex] = $occurrences[$occurrenceIndex]
                    ->withAdditionalEvidence($occurrence->evidence());
                continue;
            }

            $occurrenceIndexes[$occurrenceKey] = count($occurrences);
            $occurrences[] = $occurrence;
        }

        $impact[$index] = new SourceImpactFinding(
            $existing->affectedPackage(),
            $existing->ownership(),
            $existing->relevance(),
            $existing->reason(),
            $existing->severity(),
            $occurrences,
            array_merge($existing->evidence(), $finding->evidence())
        );

        return array_values($impact);
    }

    private function occurrenceKey(SourceUsage $usage): string
    {
        return serialize([$usage->file(), $usage->symbol(), $usage->usageType(), $usage->line()]);
    }

    /**
     * @param list<CompatibilityFinding> $frameworkFindings
     * @param array{owners: list<string>, mapping_types: list<string>, matched_prefix: ?string} $ownership
     */
    private function finding(
        SourceUsage $usage,
        array $frameworkFindings,
        ?PackageChange $packageChange,
        ?string $owner,
        array $ownership,
        ?SymbolOwnershipIndex $ownershipIndex,
        ?EvidenceLedger $evidence
    ): SourceImpactFinding {
        $frameworks = [];
        $references = $usage->evidence();
        $severity = $packageChange === null ? 'low' : $this->packageSeverity($packageChange);
        foreach ($frameworkFindings as $finding) {
            $frameworks[] = $finding->framework();
            $references = array_merge($references, $finding->evidence());
            if ($this->severityRank($finding->severity()) > $this->severityRank($severity)) {
                $severity = $finding->severity();
            }
        }

        $frameworks = array_values(array_unique($frameworks));
        sort($frameworks, SORT_STRING);
        $ownershipConfidence = $ownership['owners'] === []
            ? 'unknown'
            : (count($ownership['owners']) === 1 ? 'exact' : 'ambiguous');
        $affectedPackage = $owner === null || $ownershipIndex === null
            ? null
            : $ownershipIndex->displayOwner($owner);
        $relevance = $packageChange !== null
            ? ($frameworkFindings === [] ? 'package_change' : 'package_change_and_framework_rule')
            : 'framework_rule';

        if ($evidence !== null && $ownership['owners'] !== []) {
            $references[] = $evidence->add(
                'ownership',
                Evidence::E2_PACKAGE_METADATA,
                sprintf('Correlated %s with Composer autoload metadata.', $usage->symbol()),
                $ownershipConfidence === 'exact' ? 'high' : 'medium',
                [
                    'symbol' => $usage->symbol(),
                    'candidate_packages' => array_map(
                        static fn (string $candidate): string => $ownershipIndex === null
                            ? $candidate
                            : $ownershipIndex->describeOwner($candidate),
                        $ownership['owners']
                    ),
                    'mapping_types' => $ownership['mapping_types'],
                    'matched_prefix' => $ownership['matched_prefix'],
                ]
            )->id();
        }

        return new SourceImpactFinding(
            $affectedPackage,
            $ownershipConfidence,
            $relevance,
            $this->reason($frameworks, $packageChange, $owner, $ownership, $ownershipIndex),
            $severity,
            [$usage],
            $references
        );
    }

    /**
     * @param list<string> $frameworks
     * @param array{owners: list<string>, mapping_types: list<string>, matched_prefix: ?string} $ownership
     */
    private function reason(
        array $frameworks,
        ?PackageChange $change,
        ?string $owner,
        array $ownership,
        ?SymbolOwnershipIndex $index
    ): string {
        if ($change === null && $frameworks !== [] && $ownership['owners'] === []) {
            return sprintf(
                'Referenced by active %s compatibility guidance; package ownership has not been established.',
                implode(', ', $frameworks)
            );
        }

        $parts = [];
        if ($change !== null) {
            $parts[] = sprintf(
                'The symbol is owned by %s, which is %s%s.',
                $change->name(),
                $change->changeType(),
                $change->isMajorChange() ? ' across a major version' : ''
            );
        }
        if ($frameworks !== []) {
            $parts[] = sprintf('The usage is referenced by active %s compatibility guidance.', implode(', ', $frameworks));
        }

        if ($ownership['owners'] === []) {
            $parts[] = 'Package ownership could not be established from supported Composer autoload metadata.';
        } elseif (count($ownership['owners']) > 1) {
            $candidates = array_map(
                static fn (string $candidate): string => $index === null ? $candidate : $index->describeOwner($candidate),
                $ownership['owners']
            );
            $parts[] = sprintf('Ownership is ambiguous between %s.', implode(', ', $candidates));
        } elseif ($change === null && $owner !== null && $index !== null) {
            $parts[] = sprintf('Composer autoload metadata assigns the symbol to %s.', $index->describeOwner($owner));
        }

        return implode(' ', $parts);
    }

    /** @param list<PackageChange> $changes @return array<string, PackageChange> */
    private function relevantPackageChanges(array $changes): array
    {
        $relevant = [];
        foreach ($changes as $change) {
            if (in_array($change->changeType(), ['changed', 'removed', 'upgraded', 'downgraded'], true) || $change->isMajorChange()) {
                $relevant[$change->name()] = $change;
            }
        }
        ksort($relevant, SORT_STRING);

        return $relevant;
    }

    private function packageSeverity(PackageChange $change): string
    {
        if ($change->changeType() === 'removed' || $change->isMajorChange()) {
            return 'high';
        }

        return 'medium';
    }

    private function isSymbolUsage(SourceUsage $usage): bool
    {
        return $usage->usageType() !== 'config_reference';
    }

    private function supportsPrefixOwnership(SourceUsage $usage): bool
    {
        return $this->symbolType($usage) === 'class';
    }

    private function symbolType(SourceUsage $usage): string
    {
        if (in_array($usage->usageType(), ['constant_access', 'constant_import'], true)) {
            return 'constant';
        }
        if (in_array($usage->usageType(), ['function_call', 'function_import'], true)) {
            return 'function';
        }

        return 'class';
    }

    private function severityRank(string $severity): int
    {
        return ['low' => 1, 'medium' => 2, 'high' => 3][$severity] ?? 1;
    }
}
