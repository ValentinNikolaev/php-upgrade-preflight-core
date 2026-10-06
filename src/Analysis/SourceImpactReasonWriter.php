<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Source\SymbolOwnershipIndex;

/**
 * Writes the human-readable justification for one correlated source finding.
 *
 * Prose lives apart from correlation so that wording changes never force re-reasoning
 * about package-change or ownership semantics, and vice versa.
 */
final class SourceImpactReasonWriter
{
    /**
     * @param list<string> $frameworks
     * @param array{owners: list<string>, mapping_types: list<string>, matched_prefix: ?string} $ownership
     */
    public function write(
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
}
