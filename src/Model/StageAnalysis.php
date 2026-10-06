<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class StageAnalysis
{
    public const EXECUTED = 'evaluated';
    public const SKIPPED = 'skipped';

    private FrameworkStageTarget $target;
    private string $executionState;
    private ?string $resolutionStatus;
    /** @var list<StageAttempt> */
    private array $attempts;
    private ?int $selectedAttempt;
    private ?ProjectStateFingerprint $predecessorState;
    private ?ProjectStateFingerprint $inputState;
    private ?ProjectStateFingerprint $outputState;
    /** @var list<PackageChange> */
    private array $packageChanges;
    /** @var list<CompatibilityFinding> */
    private array $sourceFindings;
    /** @var list<SourceImpactFinding> */
    private array $sourceImpact;
    /** @var list<StageBlockerEntry> */
    private array $blockers;
    private RiskSummary $risk;
    private EffortEstimate $effort;
    /** @var list<string> */
    private array $recommendedActions;
    /** @var list<StageTestGuidance> */
    private array $tests;
    private ?string $stopReason;
    private ?StageExecutionContext $executionContext;
    /** @var list<string> */
    private array $analysisEvidence;

    /**
     * @param list<StageAttempt> $attempts
     * @param list<PackageChange> $packageChanges
     * @param list<CompatibilityFinding> $sourceFindings
     * @param list<SourceImpactFinding> $sourceImpact
     * @param list<string> $analysisEvidence
     * @param list<StageBlockerEntry> $blockers
     * @param list<string> $recommendedActions
     * @param list<StageTestGuidance> $tests
     */
    public function __construct(
        FrameworkStageTarget $target,
        string $executionState,
        ?string $resolutionStatus,
        array $attempts,
        ?int $selectedAttempt,
        ?ProjectStateFingerprint $predecessorState,
        ?ProjectStateFingerprint $inputState,
        ?ProjectStateFingerprint $outputState,
        array $packageChanges,
        ?string $stopReason = null,
        array $sourceFindings = [],
        array $sourceImpact = [],
        ?StageExecutionContext $executionContext = null,
        array $analysisEvidence = [],
        array $blockers = [],
        ?RiskSummary $risk = null,
        ?EffortEstimate $effort = null,
        array $recommendedActions = [],
        array $tests = []
    ) {
        if (!in_array($executionState, [self::EXECUTED, self::SKIPPED], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported stage execution state "%s".', $executionState));
        }
        if ($resolutionStatus !== null && !in_array($resolutionStatus, StagedResolution::statuses(), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported stage resolution status "%s".', $resolutionStatus));
        }
        if ($executionState === self::SKIPPED && ($resolutionStatus !== null || $attempts !== [] || $selectedAttempt !== null)) {
            throw new \InvalidArgumentException('Skipped stages cannot contain a resolution or attempts.');
        }
        foreach ($attempts as $attempt) {
            if (!$attempt instanceof StageAttempt) {
                throw new \InvalidArgumentException('Stage analyses may contain only StageAttempt instances.');
            }
        }
        foreach ($packageChanges as $change) {
            if (!$change instanceof PackageChange) {
                throw new \InvalidArgumentException('Stage package changes must be PackageChange instances.');
            }
        }
        foreach ($sourceFindings as $finding) {
            if (!$finding instanceof CompatibilityFinding) {
                throw new \InvalidArgumentException('Stage source findings must be CompatibilityFinding instances.');
            }
        }
        foreach ($sourceImpact as $finding) {
            if (!$finding instanceof SourceImpactFinding) {
                throw new \InvalidArgumentException('Stage source impact must contain SourceImpactFinding instances.');
            }
        }
        foreach ($blockers as $blocker) {
            if (!$blocker instanceof StageBlockerEntry || $blocker->stageId() !== $target->id()) {
                throw new \InvalidArgumentException('Stage blocker references must belong to the assessed stage.');
            }
        }
        foreach ($tests as $test) {
            if (!$test instanceof StageTestGuidance) {
                throw new \InvalidArgumentException('Stage tests must be StageTestGuidance instances.');
            }
        }

        $this->target = $target;
        $this->executionState = $executionState;
        $this->resolutionStatus = $resolutionStatus;
        $this->attempts = array_values($attempts);
        $this->selectedAttempt = $selectedAttempt;
        $this->predecessorState = $predecessorState;
        $this->inputState = $inputState;
        $this->outputState = $outputState;
        $this->packageChanges = array_values($packageChanges);
        $this->sourceFindings = array_values($sourceFindings);
        $this->sourceImpact = array_values($sourceImpact);
        $this->stopReason = $stopReason;
        $this->executionContext = $executionContext;
        $this->analysisEvidence = array_values(array_unique($analysisEvidence));
        $this->blockers = array_values($blockers);
        $this->risk = $risk ?? new RiskSummary('low', []);
        $this->effort = $effort ?? new EffortEstimate([0, 0], 'low', ['not_estimated' => [0, 0]], [
            sprintf('Stage %s was not assessed for application-change effort.', $target->id()),
        ]);
        $this->recommendedActions = array_values(array_unique($recommendedActions));
        $this->tests = array_values($tests);
    }

    public function target(): FrameworkStageTarget
    {
        return $this->target;
    }

    public function executionState(): string
    {
        return $this->executionState;
    }

    public function resolutionStatus(): ?string
    {
        return $this->resolutionStatus;
    }

    /** @return list<StageAttempt> */
    public function attempts(): array
    {
        return $this->attempts;
    }

    public function outputState(): ?ProjectStateFingerprint
    {
        return $this->outputState;
    }

    /** @return list<PackageChange> */
    public function packageChanges(): array
    {
        return $this->packageChanges;
    }

    /** @return list<SourceImpactFinding> */
    public function sourceImpact(): array
    {
        return $this->sourceImpact;
    }

    /** @return list<string> */
    public function recommendedActions(): array
    {
        return $this->recommendedActions;
    }

    /** @return list<StageTestGuidance> */
    public function tests(): array
    {
        return $this->tests;
    }

    /**
     * @param list<CompatibilityFinding> $sourceFindings
     * @param list<SourceImpactFinding> $sourceImpact
     */
    public function withSourceAssessment(array $sourceFindings, array $sourceImpact): self
    {
        return new self(
            $this->target,
            $this->executionState,
            $this->resolutionStatus,
            $this->attempts,
            $this->selectedAttempt,
            $this->predecessorState,
            $this->inputState,
            $this->outputState,
            $this->packageChanges,
            $this->stopReason,
            $sourceFindings,
            $sourceImpact,
            $this->executionContext,
            $this->analysisEvidence,
            $this->blockers,
            $this->risk,
            $this->effort,
            $this->recommendedActions,
            $this->tests
        );
    }

    /**
     * @param list<CompatibilityFinding> $sourceFindings
     * @param list<SourceImpactFinding> $sourceImpact
     * @param list<StageBlockerEntry> $blockers
     * @param list<string> $recommendedActions
     * @param list<StageTestGuidance> $tests
     */
    public function withReportingAssessment(
        array $sourceFindings,
        array $sourceImpact,
        array $blockers,
        RiskSummary $risk,
        EffortEstimate $effort,
        array $recommendedActions,
        array $tests
    ): self {
        return new self(
            $this->target,
            $this->executionState,
            $this->resolutionStatus,
            $this->attempts,
            $this->selectedAttempt,
            $this->predecessorState,
            $this->inputState,
            $this->outputState,
            $this->packageChanges,
            $this->stopReason,
            $sourceFindings,
            $sourceImpact,
            $this->executionContext,
            $this->analysisEvidence,
            $blockers,
            $risk,
            $effort,
            $recommendedActions,
            $tests
        );
    }

    /** @return list<string> */
    public function evidenceReferences(): array
    {
        $references = array_merge($this->target->evidence(), $this->analysisEvidence);
        foreach ($this->target->remediationTargets() as $target) {
            $references = array_merge($references, $this->target->remediationEvidence($target->package()));
        }
        foreach ($this->attempts as $attempt) {
            $references = array_merge($references, $attempt->evidenceReferences());
        }
        foreach ($this->sourceFindings as $finding) {
            $references = array_merge($references, $finding->evidence());
        }
        foreach ($this->sourceImpact as $finding) {
            $references = array_merge($references, $finding->evidence());
        }
        foreach ($this->blockers as $blocker) {
            $references = array_merge($references, $blocker->evidence());
        }

        return array_values(array_unique($references));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $context = $this->executionContext === null
            ? ['platform' => null, 'composer_execution' => null]
            : $this->executionContext->toArray();
        $durationMs = array_sum(array_map(
            static fn (StageAttempt $attempt): int => $attempt->scenario()->durationMs(),
            $this->attempts
        ));

        return [
            'id' => $this->target->id(),
            'framework' => $this->target->framework(),
            'from_major' => $this->target->fromMajor(),
            'to_major' => $this->target->toMajor(),
            'execution_state' => $this->executionState,
            'resolution_status' => $this->resolutionStatus,
            'targets' => $this->target->targets()->toArray(),
            'analysis_php' => $this->target->analysisPhp(),
            'target_evidence' => $this->target->evidence(),
            'platform' => $context['platform'],
            'composer_execution' => $context['composer_execution'],
            'duration_ms' => $durationMs,
            'evidence' => $this->evidenceReferences(),
            'predecessor_state' => $this->predecessorState === null ? null : $this->predecessorState->toArray(),
            'input_state' => $this->inputState === null ? null : $this->inputState->toArray(),
            'output_state' => $this->outputState === null ? null : $this->outputState->toArray(),
            'attempts' => array_map(static fn (StageAttempt $attempt): array => $attempt->toArray(), $this->attempts),
            'selected_attempt' => $this->selectedAttempt,
            'package_changes' => array_map(static fn (PackageChange $change): array => $change->toArray(), $this->packageChanges),
            'blockers' => array_map(static fn (StageBlockerEntry $blocker): string => $blocker->id(), $this->blockers),
            'source_snapshot' => 'original_project',
            'source_snapshot_note' => 'This stage assessment inspects the original project source snapshot; it does not assume edits from an earlier stage were applied.',
            'source_findings' => array_map(
                static fn (CompatibilityFinding $finding): array => $finding->toArray(),
                $this->sourceFindings
            ),
            'source_impact' => array_map(
                static fn (SourceImpactFinding $finding): string => $finding->id(),
                $this->sourceImpact
            ),
            'risk' => array_merge(['stage_id' => $this->target->id()], $this->risk->toArray()),
            'effort' => array_merge(['stage_id' => $this->target->id()], $this->effort->toArray()),
            'recommended_actions' => $this->recommendedActions,
            'tests' => array_map(static fn (StageTestGuidance $test): array => $test->toArray(), $this->tests),
            'stop_reason' => $this->stopReason,
        ];
    }
}
