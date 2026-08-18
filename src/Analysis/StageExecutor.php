<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\ProjectStateFingerprint;
use PhpUpgradePreflight\Core\Model\RootConstraintChange;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\StageAnalysis;
use PhpUpgradePreflight\Core\Model\StageAttempt;
use PhpUpgradePreflight\Core\Model\StageExecutionContext;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;

/**
 * Runs the Composer attempts of one stage against a given candidate state.
 *
 * One executor serves a whole chain: the aggregate runtime it has spent carries
 * across stages, while the per-stage runtime restarts with every stage. Time
 * budgets are checked both before an attempt starts, so no attempt may begin
 * without room for a full scenario, and after it finishes, so an overrun stops
 * the chain instead of being absorbed.
 *
 * The pre-attempt gate deliberately reserves one scenario timeout rather than the
 * attempt's whole worst case. An attempt also runs one `composer prohibits`
 * diagnostic per probed target inside the same measured window, so a stage can
 * exceed its deadline by the cost of the attempt already in flight. That is the
 * published v0.3 contract: the deadline is a stop-gate that ends the chain once
 * exhausted, not a hard kill on work already admitted. Reserving the full worst
 * case instead would refuse wide attempts that in practice finish in seconds,
 * trading a bounded overshoot for lost resolution verdicts.
 */
final class StageExecutor
{
    private ComposerScenarioRunner $runner;
    private BlockerGrouper $blockerGrouper;
    private LockDiffBuilder $lockDiffBuilder;
    private StageAttemptPlanner $attemptPlanner;
    private StageBlockerRegistry $registry;
    private int $aggregateDurationMs = 0;
    private int $stageDurationMs = 0;

    public function __construct(
        ComposerScenarioRunner $runner,
        BlockerGrouper $blockerGrouper,
        LockDiffBuilder $lockDiffBuilder,
        StageAttemptPlanner $attemptPlanner,
        StageBlockerRegistry $registry
    ) {
        $this->runner = $runner;
        $this->blockerGrouper = $blockerGrouper;
        $this->lockDiffBuilder = $lockDiffBuilder;
        $this->attemptPlanner = $attemptPlanner;
        $this->registry = $registry;
    }

    public function execute(
        FrameworkStageTarget $stage,
        ProjectState $currentState,
        TargetPlatform $platform,
        UpgradeRequest $request,
        EvidenceLedger $evidence
    ): StageOutcome {
        $execution = $request->composerExecution();
        $predecessor = ProjectStateFingerprint::fromState(
            $currentState,
            $platform,
            $stage->analysisPhp(),
            $execution->stateFingerprintData()
        );
        $attempts = [];
        $selectedAttempt = null;
        $selectedState = null;
        $selectedFingerprint = null;
        $stageStatus = StagedResolution::UNKNOWN;
        $stageStopReason = null;
        $this->stageDurationMs = 0;

        foreach ($this->attemptPlanner->definitionsFor($stage) as $attemptIndex => $definition) {
            $reservationStop = $this->budgetStopBeforeAttempt($execution->scenarioTimeoutSeconds() * 1000);
            if ($reservationStop !== null) {
                $stageStopReason = $reservationStop;
                $stageStatus = StagedResolution::UNKNOWN;
                break;
            }

            $attemptNumber = $attemptIndex + 1;
            $scenario = new Scenario(
                sprintf('%s-attempt-%d-%s', $stage->id(), $attemptNumber, $definition['strategy']),
                $definition['targets'],
                $definition['with_all_dependencies']
            );
            $result = $this->runner->run($currentState, $request, $scenario, $platform);
            $this->aggregateDurationMs += $result->durationMs();
            $this->stageDurationMs += $result->durationMs();

            $attemptEvidence = $evidence->add(
                'stage-attempt',
                Evidence::E1_SOLVER,
                sprintf('Executed Composer attempt %d for stage %s.', $attemptNumber, $stage->id()),
                'high',
                [
                    'stage_id' => $stage->id(),
                    'attempt' => $attemptNumber,
                    'strategy' => $definition['strategy'],
                    'scenario' => $scenario->name(),
                    'outcome' => $result->outcome(),
                ]
            )->id();

            $requestedConstraints = $currentState->composerJson()->rootRequirements();
            foreach ($definition['targets']->packageTargets() as $target) {
                $requestedConstraints[$target->package()] = $target->constraint();
            }
            $attemptBlockers = $this->blockerGrouper->group(
                [$result],
                $evidence,
                $result->lock() ?? $currentState->composerLock(),
                $requestedConstraints,
                $platform
            );
            $blockerIds = $this->registry->observe(
                $stage->id(),
                $attemptNumber,
                $scenario->name(),
                $attemptBlockers,
                $attemptEvidence,
                $result->succeeded() || $result->isSolverFailure()
            );

            $candidate = $result->candidateProjectState();
            $outputFingerprint = $candidate === null
                ? null
                : ProjectStateFingerprint::fromState(
                    $candidate,
                    $platform,
                    $stage->analysisPhp(),
                    $execution->stateFingerprintData()
                );
            $rootChanges = $this->rootConstraintChanges(
                $currentState,
                $stage,
                $definition['targets'],
                $evidence
            );
            $attempt = new StageAttempt(
                $attemptNumber,
                $definition['strategy'],
                $rootChanges,
                $result,
                $predecessor,
                $outputFingerprint,
                $blockerIds,
                [$attemptEvidence]
            );

            $runtimeBudgetStop = $this->budgetStopAfterAttempt();
            $stageStatus = $this->resolveStageStatus($runtimeBudgetStop, $result, $candidate, $stage->id());
            if ($stageStatus === StagedResolution::FEASIBLE && $candidate !== null) {
                $attempt = $attempt->withSelected();
                $selectedAttempt = $attemptNumber;
                $selectedState = $candidate;
                $selectedFingerprint = $outputFingerprint;
            } elseif ($stageStatus === StagedResolution::UNKNOWN) {
                $stageStopReason = $runtimeBudgetStop
                    ?? ($result->outcome() === 'timeout' ? 'timeout' : 'operational_failure');
            }

            $attempts[] = $attempt;
            if ($selectedState !== null || $stageStatus === StagedResolution::UNKNOWN) {
                break;
            }
        }

        $packageChanges = [];
        if ($selectedState !== null) {
            $packageChanges = $this->lockDiffBuilder
                ->build($currentState->composerLock(), $selectedState->composerLock())
                ->packageChanges();
            $stageStatus = $packageChanges === []
                ? StagedResolution::FEASIBLE
                : StagedResolution::FEASIBLE_WITH_CHANGES;
        } elseif ($stageStopReason === null) {
            $stageStopReason = 'blocking_registry_not_cleared';
        }

        return new StageOutcome(
            new StageAnalysis(
                $stage,
                StageAnalysis::EXECUTED,
                $stageStatus,
                $attempts,
                $selectedAttempt,
                $predecessor,
                $predecessor,
                $selectedFingerprint,
                $packageChanges,
                $stageStopReason,
                [],
                [],
                new StageExecutionContext(
                    $stage->analysisPhp(),
                    $platform,
                    $execution,
                    $predecessor
                )
            ),
            $selectedState,
            $stageStatus,
            $stageStopReason,
            $packageChanges !== []
        );
    }

    /**
     * Record a stage the chain never reached, because an earlier stage stopped it.
     */
    public function skipped(
        FrameworkStageTarget $stage,
        ProjectState $currentState,
        TargetPlatform $platform,
        UpgradeRequest $request,
        EvidenceLedger $evidence,
        string $precedingStatus
    ): StageAnalysis {
        $execution = $request->composerExecution();
        $fingerprint = ProjectStateFingerprint::fromState(
            $currentState,
            $platform,
            $stage->analysisPhp(),
            $execution->stateFingerprintData()
        );
        $skipEvidence = $evidence->add(
            'stage-skipped',
            Evidence::E1_SOLVER,
            sprintf('Stage %s was skipped after the preceding stage stopped the candidate-state chain.', $stage->id()),
            'high',
            [
                'stage_id' => $stage->id(),
                'preceding_status' => $precedingStatus,
                'reason' => 'previous_stage_' . $precedingStatus,
            ]
        )->id();

        return new StageAnalysis(
            $stage,
            StageAnalysis::SKIPPED,
            null,
            [],
            null,
            null,
            null,
            null,
            [],
            'previous_stage_' . $precedingStatus,
            [],
            [],
            new StageExecutionContext(
                $stage->analysisPhp(),
                $platform,
                $execution,
                $fingerprint
            ),
            [$skipEvidence]
        );
    }

    /**
     * A stage is feasible only when Composer resolved a candidate state within
     * budget and the stage carries no active blocking registry entry.
     */
    private function resolveStageStatus(
        ?string $runtimeBudgetStop,
        ScenarioResult $result,
        ?ProjectState $candidate,
        string $stageId
    ): string {
        if ($runtimeBudgetStop !== null) {
            return StagedResolution::UNKNOWN;
        }
        if ($result->succeeded() && $candidate !== null && !$this->registry->hasActiveBlocking($stageId)) {
            return StagedResolution::FEASIBLE;
        }
        if ($result->isSolverFailure()) {
            return StagedResolution::BLOCKED;
        }

        return StagedResolution::UNKNOWN;
    }

    /** Reserve one full scenario timeout before an attempt may start. */
    private function budgetStopBeforeAttempt(int $attemptReservationMs): ?string
    {
        $remainingAggregateMs = StagedAnalysisPolicy::AGGREGATE_TIMEOUT_SECONDS * 1000
            - $this->aggregateDurationMs;
        if ($remainingAggregateMs < $attemptReservationMs) {
            return 'aggregate_timeout';
        }
        $remainingStageMs = StagedAnalysisPolicy::STAGE_TIMEOUT_SECONDS * 1000
            - $this->stageDurationMs;
        if ($remainingStageMs < $attemptReservationMs) {
            return 'stage_timeout';
        }

        return null;
    }

    private function budgetStopAfterAttempt(): ?string
    {
        if ($this->aggregateDurationMs > StagedAnalysisPolicy::AGGREGATE_TIMEOUT_SECONDS * 1000) {
            return 'aggregate_timeout';
        }
        if ($this->stageDurationMs > StagedAnalysisPolicy::STAGE_TIMEOUT_SECONDS * 1000) {
            return 'stage_timeout';
        }

        return null;
    }

    /** @return list<RootConstraintChange> */
    private function rootConstraintChanges(
        ProjectState $state,
        FrameworkStageTarget $stage,
        UpgradeTargetSet $targets,
        EvidenceLedger $evidence
    ): array {
        $requirements = $state->composerJson()->rootRequirements();
        $changes = [];
        foreach ($targets->packageTargets() as $target) {
            $from = $requirements[$target->package()] ?? null;
            if ($from === $target->constraint()) {
                continue;
            }
            $references = $stage->remediationEvidence($target->package());
            if ($references === []) {
                $references = $stage->evidence();
            }
            $evidenceId = $evidence->add(
                'stage-root-change',
                Evidence::E2_PACKAGE_METADATA,
                sprintf('Recorded an analyzer-only root constraint change for stage %s.', $stage->id()),
                'high',
                [
                    'stage_id' => $stage->id(),
                    'package' => $target->package(),
                    'from_constraint' => $from,
                    'to_constraint' => $target->constraint(),
                    'supporting_evidence' => $references,
                ]
            )->id();
            $changes[] = new RootConstraintChange(
                $target->package(),
                $from === null ? 'added' : 'updated',
                $from,
                $target->constraint(),
                'Analyzer-only staged Composer simulation; the analyzed project was not changed.',
                array_values(array_unique(array_merge($references, [$evidenceId])))
            );
        }

        return $changes;
    }
}
