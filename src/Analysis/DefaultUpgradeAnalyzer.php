<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Contracts\UpgradeAnalyzer;
use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
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
        $project = $this->projectStateBuilder->build($request->projectPath());
        $targets = $this->targetNormalizer->normalize($request->targets()->packageTargets(), $request->targetPhp());
        $activeFrameworks = $this->frameworkRuleEngine->activeIntegrations($project, $request);
        $sourcePaths = $this->frameworkRuleEngine->sourcePaths($project, $request, $activeFrameworks);
        $analysisUncertainties = [];
        $scenarios = $this->scenarioSelector->select(
            $targets,
            $request->fromPhp(),
            $project->composerJson()->platformPhp(),
            $analysisUncertainties
        );

        $scenarioResults = [];
        foreach ($scenarios as $scenario) {
            $scenarioResults[] = $this->scenarioRunner->run($project, $request, $scenario);
        }

        $bestResult = $this->bestSuccessfulResult($project->composerLock(), $scenarioResults);
        $bestLock = $bestResult === null ? null : $bestResult->lock();

        $lockDiff = $bestLock === null ? new LockDiff([]) : $this->lockDiffBuilder->build($project->composerLock(), $bestLock);
        $blockers = $this->blockerGrouper->group($scenarioResults, $evidence);
        $sourceUncertainties = $analysisUncertainties;
        $sourceImpact = $this->sourceUsageScanner->scan(
            $project,
            $sourcePaths,
            $evidence,
            $sourceUncertainties,
            $request->sourcePaths() !== []
        );
        $frameworkFindings = $this->frameworkRuleEngine->evaluate($activeFrameworks, $project, $request, $evidence);
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
            $evidence
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
