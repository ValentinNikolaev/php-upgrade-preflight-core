<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\StageAttemptPlanner;
use PhpUpgradePreflight\Core\Analysis\StagedAnalysisPolicy;
use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class StageAttemptPlannerTest extends TestCase
{
    public function testStagesWithoutRemediationTargetsRespectThePublishedAttemptCap(): void
    {
        $definitions = (new StageAttemptPlanner())->definitionsFor($this->stage());

        self::assertSame(['target_only', 'locked_package_remediation'], array_column($definitions, 'strategy'));
        self::assertLessThanOrEqual(StagedAnalysisPolicy::MAX_ATTEMPTS_PER_STAGE, count($definitions));
    }

    public function testStagesWithRemediationTargetsRespectThePublishedAttemptCap(): void
    {
        $definitions = (new StageAttemptPlanner())->definitionsFor($this->stage([
            new UpgradeTarget('vendor/first-remediation', '^2.0'),
            new UpgradeTarget('vendor/second-remediation', '^2.0'),
        ]));

        self::assertSame([
            'target_only',
            'root_constraint_remediation',
            'root_and_locked_package_remediation',
        ], array_column($definitions, 'strategy'));
        self::assertLessThanOrEqual(StagedAnalysisPolicy::MAX_ATTEMPTS_PER_STAGE, count($definitions));
    }

    /** @param list<UpgradeTarget> $remediations */
    private function stage(array $remediations = []): FrameworkStageTarget
    {
        $evidence = [];
        foreach ($remediations as $target) {
            $evidence[$target->package()] = ['remediation-evidence'];
        }

        return new FrameworkStageTarget(
            'fixture-0-to-1',
            'fixture',
            0,
            1,
            new UpgradeTargetSet([new UpgradeTarget('vendor/framework', '^1.0')], '8.3.0'),
            '8.3.0',
            $remediations,
            $evidence,
            ['stage-evidence']
        );
    }
}
