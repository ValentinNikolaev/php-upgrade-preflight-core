<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class SourceImpactFinding
{
    private string $id;
    private ?string $affectedPackage;
    private string $ownership;
    private string $relevance;
    private string $reason;
    private string $severity;
    /** @var list<SourceUsage> */
    private array $occurrences;
    /** @var list<string> */
    private array $evidence;
    /** @var list<string> */
    private array $stageIds;

    /**
     * @param list<SourceUsage> $occurrences
     * @param list<string> $evidence
     * @param list<string> $stageIds
     */
    public function __construct(
        ?string $affectedPackage,
        string $ownership,
        string $relevance,
        string $reason,
        string $severity,
        array $occurrences,
        array $evidence,
        array $stageIds = []
    ) {
        if (!in_array($ownership, ['exact', 'ambiguous', 'unknown'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported source ownership "%s".', $ownership));
        }
        if (!in_array($relevance, ['package_change', 'framework_rule', 'package_change_and_framework_rule'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported source relevance "%s".', $relevance));
        }
        Severity::assert($severity, 'source-impact severity');
        if ($occurrences === []) {
            throw new \InvalidArgumentException('A source-impact finding must contain at least one occurrence.');
        }
        if ($evidence === []) {
            throw new \InvalidArgumentException('A source-impact finding must reference evidence.');
        }

        foreach ($occurrences as $occurrence) {
            if (!$occurrence instanceof SourceUsage) {
                throw new \InvalidArgumentException('Source-impact occurrences must be SourceUsage instances.');
            }
        }

        foreach ($stageIds as $stageId) {
            if (!is_string($stageId) || preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $stageId) !== 1) {
                throw new \InvalidArgumentException('Source-impact stage references must be stable stage IDs.');
            }
        }

        $this->affectedPackage = $affectedPackage;
        $this->ownership = $ownership;
        $this->relevance = $relevance;
        $this->reason = $reason;
        $this->severity = $severity;
        $this->occurrences = array_values($occurrences);
        $this->evidence = array_values(array_unique($evidence));
        $this->stageIds = array_values(array_unique($stageIds));
        sort($this->stageIds, SORT_STRING);
        $this->id = $this->buildId();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function affectedPackage(): ?string
    {
        return $this->affectedPackage;
    }

    public function ownership(): string
    {
        return $this->ownership;
    }

    public function relevance(): string
    {
        return $this->relevance;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    /** @return list<SourceUsage> */
    public function occurrences(): array
    {
        return $this->occurrences;
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /** @return list<string> */
    public function stageIds(): array
    {
        return $this->stageIds;
    }

    /** @param list<string> $stageIds */
    public function withStageIds(array $stageIds): self
    {
        return new self(
            $this->affectedPackage,
            $this->ownership,
            $this->relevance,
            $this->reason,
            $this->severity,
            $this->occurrences,
            $this->evidence,
            array_merge($this->stageIds, $stageIds)
        );
    }

    public function merge(self $other): self
    {
        if ($this->id !== $other->id()) {
            throw new \InvalidArgumentException('Only identical source-impact findings may be merged.');
        }

        $occurrences = [];
        foreach (array_merge($this->occurrences, $other->occurrences()) as $occurrence) {
            $key = serialize([$occurrence->file(), $occurrence->symbol(), $occurrence->usageType(), $occurrence->line()]);
            $occurrences[$key] = isset($occurrences[$key])
                ? $occurrences[$key]->withAdditionalEvidence($occurrence->evidence())
                : $occurrence;
        }

        return new self(
            $this->affectedPackage,
            $this->ownership,
            $this->relevance,
            $this->reason,
            $this->severity,
            array_values($occurrences),
            array_merge($this->evidence, $other->evidence()),
            array_merge($this->stageIds, $other->stageIds())
        );
    }

    /**
     * @return array{
     *   id: string,
     *   stage_ids: list<string>,
     *   affected_package: ?string,
     *   ownership: string,
     *   relevance: string,
     *   reason: string,
     *   severity: string,
     *   occurrences: list<array{file: string, symbol: string, usage_type: string, line: ?int, evidence: list<string>}>,
     *   evidence: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'stage_ids' => $this->stageIds,
            'affected_package' => $this->affectedPackage,
            'ownership' => $this->ownership,
            'relevance' => $this->relevance,
            'reason' => $this->reason,
            'severity' => $this->severity,
            'occurrences' => array_map(
                static fn (SourceUsage $occurrence): array => $occurrence->toArray(),
                $this->occurrences
            ),
            'evidence' => $this->evidence,
        ];
    }

    private function buildId(): string
    {
        $occurrences = array_map(
            static fn (SourceUsage $usage): array => [
                $usage->file(),
                $usage->symbol(),
                $usage->usageType(),
                $usage->line(),
            ],
            $this->occurrences
        );
        usort($occurrences, static fn (array $left, array $right): int => $left <=> $right);

        return 'source-impact-' . substr(hash('sha256', serialize([
            $this->affectedPackage,
            $this->ownership,
            $this->relevance,
            $this->reason,
            $this->severity,
            $occurrences,
        ])), 0, 20);
    }
}
