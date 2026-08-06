<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Contracts\UpgradeAnalyzer;
use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
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
    /** @var list<FrameworkIntegration> */
    private array $frameworks;

    /** @param list<FrameworkIntegration> $frameworks */
    public function __construct(
        array $frameworks = [],
        ?ProjectStateBuilder $projectStateBuilder = null,
        ?ComposerScenarioRunner $scenarioRunner = null,
        ?LockDiffBuilder $lockDiffBuilder = null,
        ?BlockerGrouper $blockerGrouper = null,
        ?SourceUsageScanner $sourceUsageScanner = null
    ) {
        $this->frameworks = array_values($frameworks);
        $this->projectStateBuilder = $projectStateBuilder ?? new ProjectStateBuilder();
        $this->scenarioRunner = $scenarioRunner ?? new ComposerScenarioRunner();
        $this->lockDiffBuilder = $lockDiffBuilder ?? new LockDiffBuilder();
        $this->blockerGrouper = $blockerGrouper ?? new BlockerGrouper();
        $this->sourceUsageScanner = $sourceUsageScanner ?? new SourceUsageScanner();
    }

    public function analyzeUpgrade(UpgradeRequest $request): UpgradeReport
    {
        $evidence = new EvidenceLedger();
        $project = $this->projectStateBuilder->build($request->projectPath);
        $activeFrameworks = $this->activeFrameworks($project, $request);
        $sourcePaths = $request->sourcePaths !== [] ? $request->sourcePaths : $this->defaultSourcePaths($project, $activeFrameworks);
        $scenarios = $this->candidateScenarios($request);

        $scenarioResults = [];
        foreach ($scenarios as $scenario) {
            $scenarioResults[] = $this->scenarioRunner->run($project, $request, $scenario);
        }

        $bestResult = $this->bestSuccessfulResult($project->composerLock, $scenarioResults);
        $bestLock = $bestResult === null ? null : $bestResult->lock;

        $lockDiff = $bestLock === null ? new LockDiff([]) : $this->lockDiffBuilder->build($project->composerLock, $bestLock);
        $blockers = $this->blockerGrouper->group($scenarioResults, $evidence);
        $sourceUncertainties = [];
        $sourceImpact = $this->sourceUsageScanner->scan(
            $project,
            $sourcePaths,
            $evidence,
            $sourceUncertainties,
            $request->sourcePaths !== []
        );
        $frameworkFindings = [];

        foreach ($activeFrameworks as $framework) {
            foreach ($framework->rules() as $rule) {
                $finding = $rule->evaluate($project, $request, $evidence);
                if ($finding !== null) {
                    $frameworkFindings[] = $finding;
                }
            }
        }

        return new UpgradeReport(
            $request,
            $project,
            $scenarioResults,
            $lockDiff,
            $blockers,
            $sourceImpact,
            $frameworkFindings,
            $this->risk($blockers, $lockDiff->packageChanges, $frameworkFindings),
            $this->effort($blockers, $lockDiff->packageChanges, $sourceImpact, $frameworkFindings),
            $this->uncertainties($scenarioResults, $sourceUncertainties),
            $evidence->all()
        );
    }

    /** @return list<Scenario> */
    private function candidateScenarios(UpgradeRequest $request): array
    {
        return [
            new Scenario('exact-target', $request->targets, false, false),
            new Scenario('target-with-all-dependencies', $request->targets, true, false),
            new Scenario('minimal-changes', $request->targets, true, true),
        ];
    }

    /** @return list<FrameworkIntegration> */
    private function activeFrameworks($project, UpgradeRequest $request): array
    {
        $requested = array_values(array_unique(array_map('strtolower', $request->frameworks)));
        $available = array_map(static fn (FrameworkIntegration $framework): string => strtolower($framework->name()), $this->frameworks);
        $unavailable = array_values(array_diff($requested, $available));

        if ($unavailable !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Requested framework integration%s unavailable: %s.',
                count($unavailable) === 1 ? ' is' : 's are',
                implode(', ', $unavailable)
            ));
        }

        $active = [];

        foreach ($this->frameworks as $framework) {
            if ($requested !== [] && !in_array(strtolower($framework->name()), $requested, true)) {
                continue;
            }

            if ($requested !== [] || $framework->detect($project)->detected) {
                $active[] = $framework;
            }
        }

        return $active;
    }

    /** @param list<ScenarioResult> $scenarioResults */
    private function bestSuccessfulResult(ComposerLock $baseline, array $scenarioResults): ?ScenarioResult
    {
        /** @var list<array{int, int, int, ScenarioResult}> $candidates */
        $candidates = [];

        foreach ($scenarioResults as $index => $result) {
            if (!$result->succeeded() || $result->lock === null) {
                continue;
            }

            $changeCount = count($this->lockDiffBuilder->build($baseline, $result->lock)->packageChanges);
            $strategyRank = $result->scenario->minimalChanges ? 1 : ($result->scenario->withAllDependencies ? 2 : 0);
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

    /** @param list<FrameworkIntegration> $frameworks @return list<string> */
    private function defaultSourcePaths($project, array $frameworks): array
    {
        $paths = [];
        foreach ($frameworks as $framework) {
            $paths = array_merge($paths, $framework->defaultSourcePaths($project));
        }

        return $paths !== [] ? array_values(array_unique($paths)) : ['src', 'app', 'config', 'routes', 'tests'];
    }

    /** @param list<mixed> $blockers @param list<mixed> $changes @param list<mixed> $findings */
    private function risk(array $blockers, array $changes, array $findings): RiskSummary
    {
        $drivers = [];
        if (count($blockers) > 0) {
            $drivers[] = 'Composer resolution is blocked.';
        }
        if (count($changes) > 20) {
            $drivers[] = 'Large lockfile transition.';
        }
        if (count($findings) > 0) {
            $drivers[] = 'Framework compatibility findings require review.';
        }

        $level = count($blockers) > 0 ? 'high' : (count($changes) > 10 || count($findings) > 2 ? 'medium' : 'low');

        return new RiskSummary($level, $drivers);
    }

    /** @param list<mixed> $blockers @param list<mixed> $changes @param list<mixed> $sourceImpact @param list<mixed> $findings */
    private function effort(array $blockers, array $changes, array $sourceImpact, array $findings): EffortEstimate
    {
        $dependency = count($blockers) > 0 ? [3, 8] : [1, max(2, min(8, count($changes)))];
        $source = [1, max(3, min(16, count($sourceImpact) + count($findings) * 2))];
        $tests = [2, 8];

        return new EffortEstimate(
            [$dependency[0] + $source[0] + $tests[0], $dependency[1] + $source[1] + $tests[1]],
            'low',
            [
                'dependency_resolution' => $dependency,
                'source_changes' => $source,
                'tests_and_debugging' => $tests,
            ],
            ['Estimate is heuristic until project-specific tests and Composer solver output are reviewed.']
        );
    }

    /** @param list<mixed> $scenarioResults @param list<string> $sourceUncertainties @return list<string> */
    private function uncertainties(array $scenarioResults, array $sourceUncertainties): array
    {
        $uncertainties = $sourceUncertainties;
        foreach ($scenarioResults as $result) {
            if ($result->isOperationalFailure()) {
                $uncertainties[] = sprintf(
                    'Composer scenario "%s" could not complete because of an analysis-environment failure.',
                    $result->scenario->name
                );
            }
        }

        return array_values(array_unique($uncertainties));
    }
}
