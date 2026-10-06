<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Framework\FrameworkStageTargetProvider;
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

/**
 * Drives the staged Composer chain.
 *
 * Resolution of the plan, per-stage Composer execution, and blocker lifecycle
 * bookkeeping each belong to a collaborator; this class owns only the chain
 * itself — which candidate state the next stage starts from, when the chain
 * stops, and what the chain concluded overall.
 */
final class StagedUpgradeOrchestrator
{
    private ComposerScenarioRunner $runner;
    private BlockerGrouper $blockerGrouper;
    private LockDiffBuilder $lockDiffBuilder;
    private StageAttemptPlanner $attemptPlanner;
    private StagePlanResolver $planResolver;

    public function __construct(
        ComposerScenarioRunner $runner,
        ?BlockerGrouper $blockerGrouper = null,
        ?LockDiffBuilder $lockDiffBuilder = null
    ) {
        $this->runner = $runner;
        $this->blockerGrouper = $blockerGrouper ?? new BlockerGrouper();
        $this->lockDiffBuilder = $lockDiffBuilder ?? new LockDiffBuilder();
        $this->attemptPlanner = new StageAttemptPlanner();
        $this->planResolver = new StagePlanResolver($this->attemptPlanner);
    }

    /** @param list<FrameworkIntegration> $activeFrameworks */
    public function analyze(
        array $activeFrameworks,
        ProjectState $project,
        UpgradeRequest $request,
        TargetPlatform $platform,
        EvidenceLedger $evidence
    ): StagedResolution {
        $providers = array_values(array_filter(
            $activeFrameworks,
            static fn (FrameworkIntegration $framework): bool => $framework instanceof FrameworkStageTargetProvider
        ));
        $plan = $this->planResolver->resolve($providers, $project, $request, $evidence);
        if ($plan->isSkipped()) {
            return $plan->skippedResolution();
        }

        $stagedRequest = $this->stagedRequest($request);
        $registry = new StageBlockerRegistry();
        $executor = $this->stageExecutor($registry);
        $currentState = $project;
        $stageAnalyses = [];
        $stopReason = null;
        $overallStatus = StagedResolution::FEASIBLE;
        $hasChanges = false;

        foreach ($plan->stages() as $stage) {
            if ($stopReason !== null) {
                $stageAnalyses[] = $executor->skipped(
                    $stage,
                    $currentState,
                    $platform,
                    $stagedRequest,
                    $evidence,
                    $overallStatus
                );
                continue;
            }

            $outcome = $executor->execute($stage, $currentState, $platform, $stagedRequest, $evidence);
            $stageAnalyses[] = $outcome->analysis();
            $selectedState = $outcome->selectedState();
            if ($selectedState === null) {
                $overallStatus = $outcome->status();
                $stopReason = $outcome->stopReason();
                continue;
            }

            $currentState = $selectedState;
            $hasChanges = $hasChanges || $outcome->hasPackageChanges();
            $overallStatus = $hasChanges
                ? StagedResolution::FEASIBLE_WITH_CHANGES
                : StagedResolution::FEASIBLE;
        }

        return new StagedResolution(
            StagedResolution::EVALUATED,
            $overallStatus,
            $plan->provider(),
            $stageAnalyses,
            $registry->ordered(),
            $stopReason,
            $plan->evidence()
        );
    }

    /** One executor serves a whole chain, so its aggregate runtime budget carries across stages. */
    private function stageExecutor(StageBlockerRegistry $registry): StageExecutor
    {
        return new StageExecutor(
            $this->runner,
            $this->blockerGrouper,
            $this->lockDiffBuilder,
            $this->attemptPlanner,
            $registry
        );
    }

    /**
     * Bound both Composer timeouts by the staged scenario policy.
     *
     * A solver-failing attempt runs one `composer prohibits` diagnostic per target
     * inside the same scenario, so an unclamped diagnostic timeout — configurable
     * up to 900 seconds — would let a single attempt outlast the scenario budget
     * that the stage and aggregate deadlines are derived from.
     */
    private function stagedRequest(UpgradeRequest $request): UpgradeRequest
    {
        $execution = $request->composerExecution();
        $scenarioTimeout = min(
            $execution->scenarioTimeoutSeconds(),
            StagedAnalysisPolicy::SCENARIO_TIMEOUT_SECONDS
        );
        $diagnosticTimeout = min(
            $execution->diagnosticTimeoutSeconds(),
            StagedAnalysisPolicy::SCENARIO_TIMEOUT_SECONDS
        );
        if ($scenarioTimeout === $execution->scenarioTimeoutSeconds()
            && $diagnosticTimeout === $execution->diagnosticTimeoutSeconds()) {
            return $request;
        }

        return $request->withComposerExecution(new ComposerExecutionConfiguration(
            $execution->executable(),
            $execution->expectedVersion(),
            $scenarioTimeout,
            $diagnosticTimeout,
            $execution->mode(),
            $execution->environmentMode(),
            $execution->networkPolicy()
        ));
    }
}
