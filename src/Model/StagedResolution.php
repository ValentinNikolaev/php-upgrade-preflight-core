<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class StagedResolution
{
    public const FEASIBLE = 'feasible';
    public const FEASIBLE_WITH_CHANGES = 'feasible_with_changes';
    public const BLOCKED = 'blocked';
    public const UNKNOWN = 'unknown';

    public const EVALUATED = 'evaluated';
    public const SKIPPED = 'skipped';

    private string $executionState;
    private string $status;
    private ?string $provider;
    /** @var list<StageAnalysis> */
    private array $stages;
    /** @var list<StageBlockerEntry> */
    private array $blockerRegistry;
    private ?string $stopReason;
    /** @var list<string> */
    private array $evidence;
    /** @var list<SourceImpactFinding> */
    private array $sourceImpact;

    /**
     * @param list<StageAnalysis> $stages
     * @param list<StageBlockerEntry> $blockerRegistry
     * @param list<string> $evidence
     * @param list<SourceImpactFinding> $sourceImpact
     */
    public function __construct(
        string $executionState,
        string $status,
        ?string $provider,
        array $stages,
        array $blockerRegistry,
        ?string $stopReason = null,
        array $evidence = [],
        array $sourceImpact = []
    ) {
        if (!in_array($executionState, [self::EVALUATED, self::SKIPPED], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported staged execution state "%s".', $executionState));
        }
        if (!in_array($status, self::statuses(), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported staged resolution status "%s".', $status));
        }
        foreach ($stages as $stage) {
            if (!$stage instanceof StageAnalysis) {
                throw new \InvalidArgumentException('Staged resolution may contain only StageAnalysis instances.');
            }
        }
        foreach ($blockerRegistry as $entry) {
            if (!$entry instanceof StageBlockerEntry) {
                throw new \InvalidArgumentException('The staged blocker registry may contain only StageBlockerEntry instances.');
            }
        }
        foreach ($sourceImpact as $finding) {
            if (!$finding instanceof SourceImpactFinding) {
                throw new \InvalidArgumentException('Staged source impact may contain only SourceImpactFinding instances.');
            }
        }

        $this->executionState = $executionState;
        $this->status = $status;
        $this->provider = $provider;
        $this->stages = array_values($stages);
        $this->blockerRegistry = array_values($blockerRegistry);
        $this->stopReason = $stopReason;
        $this->evidence = array_values(array_unique($evidence));
        $this->sourceImpact = array_values($sourceImpact);
    }

    /** @param list<string> $evidence */
    public static function skipped(string $reason, ?string $provider = null, array $evidence = []): self
    {
        return new self(self::SKIPPED, self::UNKNOWN, $provider, [], [], $reason, $evidence);
    }

    public function executionState(): string
    {
        return $this->executionState;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    /** @return list<StageAnalysis> */
    public function stages(): array
    {
        return $this->stages;
    }

    /** @return list<StageBlockerEntry> */
    public function blockerRegistry(): array
    {
        return $this->blockerRegistry;
    }

    /** @return list<SourceImpactFinding> */
    public function sourceImpact(): array
    {
        return $this->sourceImpact;
    }

    /**
     * @param list<StageAnalysis> $stages
     * @param list<SourceImpactFinding> $sourceImpact
     */
    public function withReportingAssessments(array $stages, array $sourceImpact): self
    {
        return new self(
            $this->executionState,
            $this->status,
            $this->provider,
            $stages,
            $this->blockerRegistry,
            $this->stopReason,
            $this->evidence,
            $sourceImpact
        );
    }

    /**
     * @param list<CompatibilityFinding> $findings
     * @param list<SourceImpactFinding> $sourceImpact
     */
    public function withSourceAssessments(array $findings, array $sourceImpact): self
    {
        $stages = [];
        foreach ($this->stages as $stage) {
            $stageFramework = $stage->target()->framework();
            $hop = [
                'from_major' => $stage->target()->fromMajor(),
                'to_major' => $stage->target()->toMajor(),
            ];
            $stageFindings = array_values(array_filter(
                $findings,
                static fn (CompatibilityFinding $finding): bool => strtolower($finding->framework()) === $stageFramework
                    && in_array($hop, $finding->appliesToHops(), true)
            ));
            $findingEvidence = [];
            foreach ($stageFindings as $finding) {
                $findingEvidence = array_merge($findingEvidence, $finding->evidence());
            }
            $stageImpact = array_values(array_filter(
                $sourceImpact,
                static function (SourceImpactFinding $finding) use ($findingEvidence): bool {
                    return array_intersect($finding->evidence(), $findingEvidence) !== [];
                }
            ));
            $stages[] = $stage->withSourceAssessment($stageFindings, $stageImpact);
        }

        return new self(
            $this->executionState,
            $this->status,
            $this->provider,
            $stages,
            $this->blockerRegistry,
            $this->stopReason,
            $this->evidence,
            $sourceImpact
        );
    }

    /** @return list<string> */
    public function evidenceReferences(): array
    {
        $references = $this->evidence;
        foreach ($this->stages as $stage) {
            $references = array_merge($references, $stage->evidenceReferences());
        }
        foreach ($this->blockerRegistry as $entry) {
            $references = array_merge($references, $entry->evidence());
        }
        foreach ($this->sourceImpact as $finding) {
            $references = array_merge($references, $finding->evidence());
        }

        return array_values(array_unique($references));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'execution_state' => $this->executionState,
            'status' => $this->status,
            'provider' => $this->provider,
            'stages' => array_map(static fn (StageAnalysis $stage): array => $stage->toArray(), $this->stages),
            'blocker_registry' => array_map(
                static fn (StageBlockerEntry $entry): array => $entry->toArray(),
                $this->blockerRegistry
            ),
            'source_impact' => array_map(
                static fn (SourceImpactFinding $finding): array => $finding->toArray(),
                $this->sourceImpact
            ),
            'stop_reason' => $this->stopReason,
            'budgets' => [
                'max_hops' => AnalysisBudget::MAX_HOPS,
                'max_attempts_per_stage' => AnalysisBudget::MAX_ATTEMPTS_PER_STAGE,
                'max_scenarios' => AnalysisBudget::MAX_SCENARIOS,
                'max_composer_processes' => AnalysisBudget::MAX_COMPOSER_PROCESSES,
                'scenario_timeout_seconds' => AnalysisBudget::SCENARIO_TIMEOUT_SECONDS,
                'stage_timeout_seconds' => AnalysisBudget::STAGE_TIMEOUT_SECONDS,
                'aggregate_timeout_seconds' => AnalysisBudget::AGGREGATE_TIMEOUT_SECONDS,
                'memory_bytes' => AnalysisBudget::MEMORY_BUDGET_BYTES,
                'json_report_bytes' => AnalysisBudget::JSON_REPORT_BUDGET_BYTES,
                'markdown_report_bytes' => AnalysisBudget::MARKDOWN_REPORT_BUDGET_BYTES,
            ],
            'evidence' => $this->evidence,
        ];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::FEASIBLE, self::FEASIBLE_WITH_CHANGES, self::BLOCKED, self::UNKNOWN];
    }
}
