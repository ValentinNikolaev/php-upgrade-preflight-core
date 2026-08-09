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
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Source\SourceUsageScanner;

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
        ?ScenarioSelector $scenarioSelector = null
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

        $this->scenarioRunner->resetDiagnosticCache();
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
        $sourceUncertainties = $analysisUncertainties;
        $sourceImpact = $this->sourceUsageScanner->scan(
            $project,
            $sourcePaths,
            $evidence,
            $sourceUncertainties,
            $request->sourcePaths() !== []
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
            $sourceImpact,
            $frameworkGuidance
        );
        $risk = $this->riskAndEffortEstimator->estimateRisk($blockers, $lockDiff->packageChanges(), $frameworkFindings);
        $effort = $this->riskAndEffortEstimator->estimateEffort($blockers, $lockDiff->packageChanges(), $sourceImpact, $frameworkFindings);

        return $this->reportAssembler->assemble(
            $request,
            $project,
            $scenarioResults,
            $lockDiff,
            $blockers,
            $sourceImpact,
            $frameworkFindings,
            $risk,
            $effort,
            $sourceUncertainties,
            $evidence,
            $frameworkGuidance,
            $platform
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

        $scenario = new Scenario('project-input', $request->targets(), false);
        $scenarioResult = new ScenarioResult(
            $scenario,
            1,
            '',
            $failure->getMessage(),
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

        return new UpgradeReport(
            $request,
            $projectLoad->project(),
            [$scenarioResult],
            new LockDiff([]),
            [],
            [],
            [],
            new RiskSummary('high', ['Upgrade risk could not be assessed because Composer project input is incomplete.']),
            new EffortEstimate(
                [0, 0],
                'low',
                [],
                ['Upgrade effort was not estimated because Composer project input could not be loaded.']
            ),
            [sprintf('Composer project input could not be loaded: %s', $failure->getMessage())],
            []
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
}
