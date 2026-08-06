<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\SourceUsage;

final class RiskAndEffortEstimator
{
    /**
     * @param list<Blocker> $blockers
     * @param list<PackageChange> $changes
     * @param list<CompatibilityFinding> $findings
     */
    public function estimateRisk(array $blockers, array $changes, array $findings): RiskSummary
    {
        $drivers = [];
        if ($blockers !== []) {
            $drivers[] = 'Composer resolution is blocked.';
        }
        if (count($changes) > 20) {
            $drivers[] = 'Large lockfile transition.';
        }
        if ($findings !== []) {
            $drivers[] = 'Framework compatibility findings require review.';
        }

        $level = $blockers !== [] ? 'high' : (count($changes) > 10 || count($findings) > 2 ? 'medium' : 'low');

        return new RiskSummary($level, $drivers);
    }

    /**
     * @param list<Blocker> $blockers
     * @param list<PackageChange> $changes
     * @param list<SourceUsage> $sourceImpact
     * @param list<CompatibilityFinding> $findings
     */
    public function estimateEffort(
        array $blockers,
        array $changes,
        array $sourceImpact,
        array $findings
    ): EffortEstimate {
        $dependency = $blockers !== [] ? [3, 8] : [1, max(2, min(8, count($changes)))];
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
}
