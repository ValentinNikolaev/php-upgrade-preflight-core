<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\RiskAndEffortEstimator;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\SourceImpactFinding;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PHPUnit\Framework\TestCase;

final class RiskAndEffortEstimatorTest extends TestCase
{
    public function testBlockersDriveHighRiskAndTheDependencyEffortRange(): void
    {
        $estimator = new RiskAndEffortEstimator();
        $blockers = [new Blocker('conflict', 'vendor/package', 'Blocked.', 'high', ['solver-1'])];
        $changes = [new PackageChange('vendor/package', 'upgraded', '1.0.0', '2.0.0', true)];
        $sourceImpact = [
            new SourceImpactFinding('vendor/package', 'exact', 'package_change', 'Changed package.', 'medium', [
                new SourceUsage('src/Example.php', 'Vendor\\Package', 'namespace_import', ['source-1']),
            ], ['source-1']),
            new SourceImpactFinding('vendor/package', 'exact', 'package_change', 'Changed package.', 'medium', [
                new SourceUsage('src/Other.php', 'Vendor\\Package', 'static_call', ['source-2']),
            ], ['source-2']),
        ];
        $findings = [new CompatibilityFinding('fixture', 'medium', 'Review.', ['framework-1'])];

        $risk = $estimator->estimateRisk($blockers, $changes, $findings, $sourceImpact);
        $effort = $estimator->estimateEffort($blockers, $changes, $sourceImpact, $findings);

        self::assertSame('high', $risk->level());
        self::assertSame([
            'Composer resolution is blocked.',
            'Framework compatibility findings require review.',
            'Weighted actionable source findings require review.',
        ], $risk->drivers());
        self::assertSame([6, 22], $effort->rangeHours());
        self::assertSame([3, 8], $effort->components()['dependency_resolution']);
        self::assertSame([1, 6], $effort->components()['source_changes']);
    }

    public function testALargerTransitionProducesMediumRiskWithoutBlockers(): void
    {
        $changes = [];
        for ($index = 1; $index <= 11; ++$index) {
            $changes[] = new PackageChange('vendor/package-' . $index, 'upgraded', '1.0.0', '2.0.0');
        }

        $risk = (new RiskAndEffortEstimator())->estimateRisk([], $changes, []);

        self::assertSame('medium', $risk->level());
        self::assertSame([], $risk->drivers());
    }

    public function testAbandonedPackageAdvisoriesDoNotClaimComposerResolutionIsBlocked(): void
    {
        $advisory = new Blocker(
            'abandoned-package',
            'vendor/legacy',
            'Abandoned.',
            'high',
            ['lock-metadata-1']
        );

        $risk = (new RiskAndEffortEstimator())->estimateRisk([$advisory], [], []);

        self::assertFalse($advisory->blocksResolution());
        self::assertSame('medium', $risk->level());
        self::assertSame(['Abandoned packages require replacement or removal.'], $risk->drivers());
    }

    public function testMixedBlockersRetainResolutionAndAdvisoryRiskDrivers(): void
    {
        $blockers = [
            new Blocker('conflict', 'vendor/package', 'Blocked.', 'high', ['solver-1']),
            new Blocker('abandoned-package', 'vendor/legacy', 'Abandoned.', 'high', ['lock-metadata-1']),
        ];

        $risk = (new RiskAndEffortEstimator())->estimateRisk($blockers, [], []);

        self::assertTrue($blockers[0]->blocksResolution());
        self::assertSame('high', $risk->level());
        self::assertSame([
            'Composer resolution is blocked.',
            'Abandoned packages require replacement or removal.',
        ], $risk->drivers());
    }

    public function testMoreThanTwentyPackageChangesAddTheLargeTransitionDriver(): void
    {
        $changes = [];
        for ($index = 1; $index <= 21; ++$index) {
            $changes[] = new PackageChange('vendor/package-' . $index, 'upgraded', '1.0.0', '2.0.0');
        }

        $risk = (new RiskAndEffortEstimator())->estimateRisk([], $changes, []);

        self::assertSame('medium', $risk->level());
        self::assertSame(['Large lockfile transition.'], $risk->drivers());
    }

    public function testWeightedActionableFindingsDriveRiskAndEffortButUnrelatedInventoryCannot(): void
    {
        $estimator = new RiskAndEffortEstimator();
        $actionable = [new SourceImpactFinding(
            'vendor/package',
            'ambiguous',
            'package_change_and_framework_rule',
            'Changed ambiguous package usage.',
            'high',
            [
                new SourceUsage('src/First.php', 'Shared\\Client', 'static_call', ['source-1'], 10),
                new SourceUsage('src/Second.php', 'Shared\\Client', 'static_call', ['source-2'], 20),
            ],
            ['source-1', 'source-2']
        )];
        $unrelatedInventory = [];
        for ($index = 1; $index <= 100; ++$index) {
            $unrelatedInventory[] = new SourceUsage(
                'src/Unrelated.php',
                'App\\Domain\\Type' . $index,
                'namespace_import',
                ['unrelated-' . $index],
                $index
            );
        }

        $baselineRisk = $estimator->estimateRisk([], [], []);
        $baselineEffort = $estimator->estimateEffort([], [], [], []);
        $actionableRisk = $estimator->estimateRisk([], [], [], $actionable);
        $actionableEffort = $estimator->estimateEffort([], [], $actionable, []);

        self::assertCount(100, $unrelatedInventory);
        self::assertSame('low', $baselineRisk->level());
        self::assertSame([1, 3], $baselineEffort->components()['source_changes']);
        self::assertSame('medium', $actionableRisk->level());
        self::assertSame(['Weighted actionable source findings require review.'], $actionableRisk->drivers());
        self::assertSame([1, 7], $actionableEffort->components()['source_changes']);
    }
}
