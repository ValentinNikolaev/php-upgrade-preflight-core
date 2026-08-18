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

/**
 * Correlates scanned source usages with package changes and framework compatibility rules.
 *
 * Merging is delegated to {@see SourceImpactAccumulator} and justification prose to
 * {@see SourceImpactReasonWriter}, leaving this class responsible only for deciding which
 * usages matter and what their severity, ownership, and relevance are.
 */
final class SourceImpactBuilder
{
    private SourceImpactReasonWriter $reasonWriter;

    public function __construct(?SourceImpactReasonWriter $reasonWriter = null)
    {
        $this->reasonWriter = $reasonWriter ?? new SourceImpactReasonWriter();
    }

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
        $impact = new SourceImpactAccumulator();
        $relevantChanges = $this->relevantPackageChanges($packageChanges);

        foreach ($inventory as $usage) {
            $matchingFindings = $this->matchingFindings($usage, $frameworkFindings);
            $ownership = $this->ownership($usage, $ownershipIndex);

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
                    $impact->add($this->finding(
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

            $impact->add($this->finding(
                $usage,
                $matchingFindings,
                null,
                count($ownership['owners']) === 1 ? $ownership['owners'][0] : null,
                $ownership,
                $ownershipIndex,
                $evidence
            ));
        }

        return $impact->findings();
    }

    /**
     * @param list<CompatibilityFinding> $frameworkFindings
     * @return list<CompatibilityFinding>
     */
    private function matchingFindings(SourceUsage $usage, array $frameworkFindings): array
    {
        return array_values(array_filter(
            $frameworkFindings,
            static fn (CompatibilityFinding $finding): bool => array_intersect(
                $usage->evidence(),
                $finding->evidence()
            ) !== []
        ));
    }

    /** @return array{owners: list<string>, mapping_types: list<string>, matched_prefix: ?string} */
    private function ownership(SourceUsage $usage, ?SymbolOwnershipIndex $ownershipIndex): array
    {
        if ($ownershipIndex === null || !$this->isSymbolUsage($usage)) {
            return ['owners' => [], 'mapping_types' => [], 'matched_prefix' => null];
        }

        return $ownershipIndex->lookup(
            $usage->symbol(),
            $this->supportsPrefixOwnership($usage),
            $this->symbolType($usage)
        );
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
            $references[] = $evidence->addOnce(
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
            $this->reasonWriter->write($frameworks, $packageChange, $owner, $ownership, $ownershipIndex),
            $severity,
            [$usage],
            $references
        );
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

    /**
     * Autoload ownership only means something for PHP symbols. A `config_reference`
     * symbol is an opaque dotted configuration key, so looking it up would prefix-match
     * it against PSR-4 roots and invent an owner.
     *
     * Known seam: `config_reference` is adapter vocabulary (the Laravel visitor emits it),
     * so core naming it here is a residual coupling. The durable fix is for a usage to
     * declare whether its symbol is a PHP symbol or an opaque key, rather than for core
     * to keep a list of adapter usage types. That is a SourceUsage model change and a
     * separate decision; this guard stays because it is load-bearing, not dead.
     */
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
