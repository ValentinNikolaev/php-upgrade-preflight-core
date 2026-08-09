<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\SourceImpactFinding;

final class RiskAndEffortEstimator
{
    /**
     * @param list<Blocker> $blockers
     * @param list<PackageChange> $changes
     * @param list<CompatibilityFinding> $findings
     * @param list<SourceImpactFinding> $sourceImpact
     */
    public function estimateRisk(array $blockers, array $changes, array $findings, array $sourceImpact = []): RiskSummary
    {
        $drivers = [];
        $hasResolutionBlocker = $this->hasResolutionBlocker($blockers);
        $hasAdvisory = $this->hasAdvisory($blockers);
        $sourceWeight = $this->actionableWeight($sourceImpact);

        if ($hasResolutionBlocker) {
            $drivers[] = 'Composer resolution is blocked.';
        }
        if ($hasAdvisory) {
            $drivers[] = 'Abandoned packages require replacement or removal.';
        }
        if (count($changes) > 20) {
            $drivers[] = 'Large lockfile transition.';
        }
        if ($findings !== []) {
            $drivers[] = 'Framework compatibility findings require review.';
        }
        if ($sourceWeight >= 4) {
            $drivers[] = 'Weighted actionable source findings require review.';
        }

        $level = $hasResolutionBlocker
            ? 'high'
            : ($sourceWeight >= 10
                ? 'high'
                : ($hasAdvisory || count($changes) > 10 || count($findings) > 2 || $sourceWeight >= 4 ? 'medium' : 'low'));

        return new RiskSummary($level, $drivers);
    }

    /**
     * @param list<Blocker> $blockers
     * @param list<PackageChange> $changes
     * @param list<SourceImpactFinding> $sourceImpact
     * @param list<CompatibilityFinding> $findings
     */
    public function estimateEffort(
        array $blockers,
        array $changes,
        array $sourceImpact,
        array $findings
    ): EffortEstimate {
        $dependency = $blockers !== [] ? [3, 8] : [1, max(2, min(8, count($changes)))];
        $source = [1, max(3, min(16, $this->actionableWeight($sourceImpact) + count($findings) * 2))];
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

    /** @param list<Blocker> $blockers */
    private function hasResolutionBlocker(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if ($blocker->blocksResolution()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Blocker> $blockers */
    private function hasAdvisory(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (!$blocker->blocksResolution()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<SourceImpactFinding> $findings */
    private function actionableWeight(array $findings): int
    {
        $weight = 0;
        foreach ($findings as $finding) {
            $findingWeight = ['low' => 1, 'medium' => 2, 'high' => 4][$finding->severity()] ?? 1;
            if ($finding->relevance() === 'package_change_and_framework_rule') {
                ++$findingWeight;
            }
            if ($finding->ownership() !== 'exact') {
                ++$findingWeight;
            }

            $findingWeight += min(3, max(0, count($finding->occurrences()) - 1));
            $weight += $findingWeight;
        }

        return $weight;
    }
}
