<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\SourceImpactFinding;
use PhpUpgradePreflight\Core\Model\SourceUsage;

/**
 * Collects correlated source findings, merging the ones that describe the same conclusion.
 *
 * Two findings collapse when their package, ownership, relevance, reason, severity, and
 * leading symbol usage agree; their occurrences and evidence are then unioned so a single
 * finding still cites every location that produced it.
 */
final class SourceImpactAccumulator
{
    /** @var array<int, SourceImpactFinding> */
    private array $impact = [];
    /** @var array<string, int> */
    private array $indexes = [];

    public function add(SourceImpactFinding $finding): void
    {
        $key = $this->findingKey($finding);
        if (!isset($this->indexes[$key])) {
            $this->indexes[$key] = count($this->impact);
            $this->impact[] = $finding;

            return;
        }

        $index = $this->indexes[$key];
        $existing = $this->impact[$index];
        $this->impact[$index] = new SourceImpactFinding(
            $existing->affectedPackage(),
            $existing->ownership(),
            $existing->relevance(),
            $existing->reason(),
            $existing->severity(),
            $this->mergeOccurrences($existing->occurrences(), $finding->occurrences()),
            array_merge($existing->evidence(), $finding->evidence())
        );
    }

    /** @return list<SourceImpactFinding> */
    public function findings(): array
    {
        return array_values($this->impact);
    }

    private function findingKey(SourceImpactFinding $finding): string
    {
        $first = $finding->occurrences()[0];

        return serialize([
            $finding->affectedPackage(),
            $finding->ownership(),
            $finding->relevance(),
            $finding->reason(),
            $finding->severity(),
            $first->symbol(),
            $first->usageType(),
        ]);
    }

    /**
     * @param list<SourceUsage> $occurrences
     * @param list<SourceUsage> $additional
     * @return list<SourceUsage>
     */
    private function mergeOccurrences(array $occurrences, array $additional): array
    {
        $indexes = [];
        foreach ($occurrences as $occurrenceIndex => $occurrence) {
            $indexes[$this->occurrenceKey($occurrence)] = $occurrenceIndex;
        }
        foreach ($additional as $occurrence) {
            $key = $this->occurrenceKey($occurrence);
            if (isset($indexes[$key])) {
                $index = $indexes[$key];
                $occurrences[$index] = $occurrences[$index]->withAdditionalEvidence($occurrence->evidence());
                continue;
            }

            $indexes[$key] = count($occurrences);
            $occurrences[] = $occurrence;
        }

        return array_values($occurrences);
    }

    private function occurrenceKey(SourceUsage $usage): string
    {
        return serialize([$usage->file(), $usage->symbol(), $usage->usageType(), $usage->line()]);
    }
}
