<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\StagePlanResolution;
use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class StagePlanResolutionTest extends TestCase
{
    public function testAnExecutablePlanExposesItsValidatedChain(): void
    {
        $stage = new FrameworkStageTarget(
            'fixture-0-to-1',
            'fixture',
            0,
            1,
            new UpgradeTargetSet([new UpgradeTarget('vendor/framework', '^1.0')], '8.3.0'),
            '8.3.0',
            [],
            [],
            ['stage-evidence']
        );
        $plan = StagePlanResolution::executable('fixture', [$stage], ['stage-evidence']);

        self::assertFalse($plan->isSkipped());
        self::assertSame('fixture', $plan->provider());
        self::assertSame([$stage], $plan->stages());
        self::assertSame(['stage-evidence'], $plan->evidence());
    }

    public function testAnExecutablePlanCarriesNoSkippedResolution(): void
    {
        $plan = StagePlanResolution::executable('fixture', [], []);

        $this->expectException(\LogicException::class);
        $plan->skippedResolution();
    }

    public function testASkippedPlanExposesItsFinishedResolution(): void
    {
        $skipped = StagedResolution::skipped('stage_target_provider_unavailable');
        $plan = StagePlanResolution::skipped($skipped);

        self::assertTrue($plan->isSkipped());
        self::assertSame($skipped, $plan->skippedResolution());
        self::assertSame([], $plan->stages());
        self::assertSame([], $plan->evidence());
    }

    public function testASkippedPlanCarriesNoExecutableProvider(): void
    {
        $plan = StagePlanResolution::skipped(StagedResolution::skipped('stage_target_provider_unavailable'));

        $this->expectException(\LogicException::class);
        $plan->provider();
    }
}
