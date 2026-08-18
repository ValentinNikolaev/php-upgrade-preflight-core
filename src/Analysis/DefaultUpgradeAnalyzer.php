<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\InvalidJsonException;
use PhpUpgradePreflight\Core\Composer\MissingJsonFileException;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Composer\ProjectStateLoadResult;
use PhpUpgradePreflight\Core\Contracts\UpgradeAnalyzer;
use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Source\SourceUsageScanner;
use PhpUpgradePreflight\Core\Source\AutoloadOwnershipIndexBuilder;
use PhpUpgradePreflight\Core\Support\PathExposurePolicy;

final class DefaultUpgradeAnalyzer implements UpgradeAnalyzer
{
    private ProjectStateBuilder $projectStateBuilder;
    private ComposerScenarioRunner $scenarioRunner;
    private LockDiffBuilder $lockDiffBuilder;
    private BlockerGrouper $blockerGrouper;
    private SourceUsageScanner $sourceUsageScanner;
    private TargetNormalizer $targetNormalizer;
    private ScenarioSelector $scenarioSelector;
    private FrameworkRuleEngine $frameworkRuleEngine;
    private RiskAndEffortEstimator $riskAndEffortEstimator;
    private ReportAssembler $reportAssembler;
    private AutoloadOwnershipIndexBuilder $ownershipIndexBuilder;
    private SourceImpactBuilder $sourceImpactBuilder;
    private StagedUpgradeOrchestrator $stagedUpgradeOrchestrator;
    private StageAssessmentBuilder $stageAssessmentBuilder;

    /** @param list<FrameworkIntegration> $frameworks */
    public function __construct(
        array $frameworks = [],
        ?ProjectStateBuilder $projectStateBuilder = null,
        ?ComposerScenarioRunner $scenarioRunner = null,
        ?LockDiffBuilder $lockDiffBuilder = null,
        ?BlockerGrouper $blockerGrouper = null,
        ?SourceUsageScanner $sourceUsageScanner = null,
        ?TargetNormalizer $targetNormalizer = null,
        ?FrameworkRuleEngine $frameworkRuleEngine = null,
        ?RiskAndEffortEstimator $riskAndEffortEstimator = null,
        ?ReportAssembler $reportAssembler = null,
        ?ScenarioSelector $scenarioSelector = null,
        ?AutoloadOwnershipIndexBuilder $ownershipIndexBuilder = null,
        ?SourceImpactBuilder $sourceImpactBuilder = null,
        ?StagedUpgradeOrchestrator $stagedUpgradeOrchestrator = null,
        ?StageAssessmentBuilder $stageAssessmentBuilder = null
    ) {
        $this->projectStateBuilder = $projectStateBuilder ?? new ProjectStateBuilder();
        $this->scenarioRunner = $scenarioRunner ?? new ComposerScenarioRunner();
        $this->lockDiffBuilder = $lockDiffBuilder ?? new LockDiffBuilder();
        $this->blockerGrouper = $blockerGrouper ?? new BlockerGrouper();
        $this->sourceUsageScanner = $sourceUsageScanner ?? new SourceUsageScanner();
        $this->targetNormalizer = $targetNormalizer ?? new TargetNormalizer();
        $this->scenarioSelector = $scenarioSelector ?? new ScenarioSelector();
        $this->frameworkRuleEngine = $frameworkRuleEngine ?? new FrameworkRuleEngine($frameworks);
        $this->riskAndEffortEstimator = $riskAndEffortEstimator ?? new RiskAndEffortEstimator();
        $this->reportAssembler = $reportAssembler ?? new ReportAssembler();
        $this->ownershipIndexBuilder = $ownershipIndexBuilder ?? new AutoloadOwnershipIndexBuilder();
        $this->sourceImpactBuilder = $sourceImpactBuilder ?? new SourceImpactBuilder();
        $this->stagedUpgradeOrchestrator = $stagedUpgradeOrchestrator
            ?? new StagedUpgradeOrchestrator($this->scenarioRunner, $this->blockerGrouper, $this->lockDiffBuilder);
        $this->stageAssessmentBuilder = $stageAssessmentBuilder
            ?? new StageAssessmentBuilder($this->sourceImpactBuilder, $this->riskAndEffortEstimator);
    }

    public function analyzeUpgrade(UpgradeRequest $request): UpgradeReport
    {
        $evidence = new EvidenceLedger();
        $projectLoad = $this->projectStateBuilder->load($request->projectPath());
        if (!$projectLoad->succeeded()) {
            return $this->inputFailureReport($request, $projectLoad);
        }

        $project = $projectLoad->project();
        $platform = TargetPlatform::fromRequest($request, $project);
        $targets = $this->targetNormalizer->normalize($request->targets()->packageTargets(), $request->targetPhp());
        $activeFrameworks = $this->frameworkRuleEngine->activeIntegrations($project, $request);
        $packageFamilyClassifiers = $this->frameworkRuleEngine->packageFamilyClassifiers($activeFrameworks);
        $sourcePaths = $this->frameworkRuleEngine->sourcePaths($project, $request, $activeFrameworks);
        $analysisUncertainties = [];
        $scenarios = $this->scenarioSelector->select(
            $targets,
            $request->fromPhp(),
            $project->composerJson()->platformPhp(),
            $analysisUncertainties
        );

        $this->scenarioRunner->resetAnalysisCaches();
        $scenarioResults = [];
        foreach ($scenarios as $scenario) {
            $scenarioResults[] = $this->scenarioRunner->run($project, $request, $scenario, $platform);
        }

        $bestResult = $this->bestSuccessfulResult($project->composerLock(), $scenarioResults);
        $bestLock = $bestResult === null ? null : $bestResult->lock();

        $lockDiff = $bestLock === null
            ? new LockDiff([])
            : $this->lockDiffBuilder->build($project->composerLock(), $bestLock, $packageFamilyClassifiers);
        $requestedConstraints = $project->composerJson()->rootRequirements();
        foreach ($targets->packageTargets() as $target) {
            $requestedConstraints[$target->package()] = $target->constraint();
        }
        $blockers = $this->blockerGrouper->group(
            $scenarioResults,
            $evidence,
            $bestLock ?? $project->composerLock(),
            $requestedConstraints,
            $platform
        );
        $stagedResolution = $this->stagedUpgradeOrchestrator->analyze(
            $activeFrameworks,
            $project,
            $request,
            $platform,
            $evidence
        );
        // A metadata-probe workspace the analyzer could not remove leaves state on disk and makes
        // every Composer version or platform answer derived from that probe suspect, so it has to
        // reach the report rather than staying an in-process accessor nobody reads. Candidate locks
        // are collected after the staged chain for the same reason: a scenario or stage lock entry
        // the analyzer could not index is gone once the workspace is removed, and the candidate
        // package count and package changes silently exclude it.
        $sourceUncertainties = array_merge(
            $analysisUncertainties,
            $this->scenarioRunner->probeCleanupUncertainties(),
            $project->composerLock()->unusablePackageUncertainties(),
            $this->scenarioRunner->candidateLockUncertainties()
        );
        $sourceInventory = $this->sourceUsageScanner->scan(
            $project,
            array_values($sourcePaths),
            $evidence,
            $sourceUncertainties,
            $request->sourcePaths() !== [],
            $activeFrameworks
        );
        $frameworkGuidance = $this->frameworkRuleEngine->assessTransitions(
            $activeFrameworks,
            $project,
            $request,
            $evidence
        );
        $frameworkFindings = $this->frameworkRuleEngine->evaluate(
            $activeFrameworks,
            $project,
            $request,
            $evidence,
            $sourceInventory,
            $frameworkGuidance,
            $this->composerVersion($scenarioResults),
            // A rule that throws is skipped rather than ending the run, but the evidence
            // recorded for that skip is only reachable through the uncertainty that cites
            // it. Dropping the appended entries here would orphan that evidence and turn
            // a contained adapter defect back into a failed report.
            $sourceUncertainties
        );
        $ownershipIndex = $this->ownershipIndexBuilder->build(
            $project,
            $sourceUncertainties,
            array_map(static fn ($usage): string => $usage->symbol(), $sourceInventory)
        );
        $actionableSourceImpact = $this->sourceImpactBuilder->build(
            $sourceInventory,
            $frameworkFindings,
            $lockDiff->packageChanges(),
            $ownershipIndex,
            $evidence
        );
        $stagedResolution = $this->stageAssessmentBuilder->build(
            $stagedResolution,
            $sourceInventory,
            $frameworkFindings,
            $ownershipIndex,
            $evidence,
            $project,
            $request
        );
        $risk = $this->riskAndEffortEstimator->estimateAggregateRisk(
            $blockers,
            $lockDiff->packageChanges(),
            $frameworkFindings,
            $actionableSourceImpact,
            $stagedResolution
        );
        $effort = $this->riskAndEffortEstimator->estimateAggregateEffort(
            $blockers,
            $lockDiff->packageChanges(),
            $actionableSourceImpact,
            $frameworkFindings,
            $stagedResolution
        );

        return $this->reportAssembler->assemble(
            $request,
            $project,
            $scenarioResults,
            $lockDiff,
            $blockers,
            $sourceInventory,
            $actionableSourceImpact,
            $frameworkFindings,
            $risk,
            $effort,
            $sourceUncertainties,
            $evidence,
            $frameworkGuidance,
            $platform,
            $stagedResolution
        );
    }

    private function inputFailureReport(UpgradeRequest $request, ProjectStateLoadResult $projectLoad): UpgradeReport
    {
        $failure = $projectLoad->failure();
        if ($failure === null) {
            throw new \LogicException('A failed project-state load must contain its failure.');
        }

        if ($failure instanceof InvalidJsonException) {
            $outcome = ScenarioResult::OUTCOME_INVALID_JSON;
        } elseif ($failure instanceof MissingJsonFileException && basename($failure->path()) === 'composer.lock') {
            $outcome = ScenarioResult::OUTCOME_LOCKFILE_MISSING;
        } else {
            $outcome = ScenarioResult::OUTCOME_WORKSPACE_FAILURE;
        }

        $safeFailureMessage = PathExposurePolicy::redactComposerText(
            $failure->getMessage(),
            $request->projectPath()
        );

        $scenario = new Scenario('project-input', $request->targets(), false);
        $scenarioResult = new ScenarioResult(
            $scenario,
            1,
            '',
            $safeFailureMessage,
            null,
            null,
            ScenarioResult::FAILURE_OPERATIONAL,
            null,
            [],
            0,
            null,
            [],
            $outcome
        );

        return ReportAssembler::inputFailure(
            $request,
            $projectLoad->project(),
            $scenarioResult,
            $safeFailureMessage
        );
    }

    /** @param list<ScenarioResult> $scenarioResults */
    private function bestSuccessfulResult(ComposerLock $baseline, array $scenarioResults): ?ScenarioResult
    {
        /** @var list<array{int, int, int, ScenarioResult}> $candidates */
        $candidates = [];

        foreach ($scenarioResults as $index => $result) {
            if (!$result->scenario()->determinesTargetFeasibility() || !$result->succeeded() || $result->lock() === null) {
                continue;
            }

            $changeCount = count($this->lockDiffBuilder->build($baseline, $result->lock())->packageChanges());
            $strategyRank = $result->scenario()->minimalChanges() ? 1 : ($result->scenario()->withAllDependencies() ? 2 : 0);
            $candidates[] = [$changeCount, $strategyRank, $index, $result];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            return [$left[0], $left[1], $left[2]] <=> [$right[0], $right[1], $right[2]];
        });

        return $candidates[0][3];
    }

    /** @param list<ScenarioResult> $scenarioResults */
    private function composerVersion(array $scenarioResults): ?string
    {
        foreach ($scenarioResults as $result) {
            if ($result->composerVersion() !== null) {
                return $result->composerVersion();
            }
        }

        return null;
    }
}
