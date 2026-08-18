<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\RiskAndEffortEstimator;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\SourceImpactFinding;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\StageAnalysis;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class RiskAndEffortEstimatorTest extends TestCase
{
    public function testRepeatedSourceImpactDoesNotInflateAggregateEffort(): void
    {
        $usage = new \PhpUpgradePreflight\Core\Model\SourceUsage(
            'src/Fixture.php',
            'Fixture\\Client',
            'instantiated_class',
            ['source-1'],
            10
        );
        $impact = new \PhpUpgradePreflight\Core\Model\SourceImpactFinding(
            'fixture/package',
            'exact',
            'package_change',
            'The owning package changes.',
            'medium',
            [$usage],
            ['source-1']
        );
        $estimator = new RiskAndEffortEstimator();
        $single = $estimator->estimateEffort([], [], [$impact], []);
        $repeated = $estimator->estimateEffort([], [], [$impact->merge($impact)], []);

        self::assertSame($single->rangeHours(), $repeated->rangeHours());
    }

    public function testRepeatedHopFindingDoesNotInflateAggregateEffort(): void
    {
        $first = new CompatibilityFinding(
            'fixture',
            'medium',
            'Review the same framework change.',
            ['docs-1'],
            [['from_major' => 1, 'to_major' => 2]]
        );
        $second = new CompatibilityFinding(
            'fixture',
            'medium',
            'Review the same framework change.',
            ['docs-2'],
            [['from_major' => 2, 'to_major' => 3]]
        );
        $estimator = new RiskAndEffortEstimator();
        $staged = StagedResolution::skipped('stage_target_provider_unavailable');

        self::assertSame(
            $estimator->estimateAggregateEffort([], [], [], [$first], $staged)->rangeHours(),
            $estimator->estimateAggregateEffort([], [], [], [$first, $second], $staged)->rangeHours()
        );
    }

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

    public function testWholeUpgradeRiskThresholdsAreExactBoundaries(): void
    {
        $estimator = new RiskAndEffortEstimator();

        self::assertSame('low', $estimator->estimateRisk([], $this->changes(10), [])->level());
        self::assertSame('medium', $estimator->estimateRisk([], $this->changes(11), [])->level());
        self::assertSame('low', $estimator->estimateRisk([], [], $this->findings(2))->level());
        self::assertSame('medium', $estimator->estimateRisk([], [], $this->findings(3))->level());
        self::assertSame([], $estimator->estimateRisk([], $this->changes(20), [])->drivers());
        self::assertSame(
            ['Large lockfile transition.'],
            $estimator->estimateRisk([], $this->changes(21), [])->drivers()
        );
    }

    public function testWeightedSourceImpactEscalatesToHighRiskOnlyAtItsThreshold(): void
    {
        $estimator = new RiskAndEffortEstimator();
        $heavy = new SourceImpactFinding(
            'vendor/package',
            'ambiguous',
            'package_change_and_framework_rule',
            'Ambiguous shared usage changes.',
            'high',
            [
                new SourceUsage('src/A.php', 'Shared\\Client', 'static_call', ['source-1'], 1),
                new SourceUsage('src/B.php', 'Shared\\Client', 'static_call', ['source-2'], 2),
                new SourceUsage('src/C.php', 'Shared\\Client', 'static_call', ['source-3'], 3),
                new SourceUsage('src/D.php', 'Shared\\Client', 'static_call', ['source-4'], 4),
            ],
            ['source-1', 'source-2', 'source-3', 'source-4']
        );
        $light = new SourceImpactFinding(
            'vendor/other',
            'exact',
            'package_change',
            'Exact usage changes.',
            'low',
            [new SourceUsage('src/E.php', 'Other\\Client', 'static_call', ['source-5'], 5)],
            ['source-5']
        );

        self::assertSame('medium', $estimator->estimateRisk([], [], [], [$heavy])->level());
        self::assertSame('high', $estimator->estimateRisk([], [], [], [$heavy, $light])->level());
    }

    public function testStageRiskUsesItsOwnNarrowerRuleSet(): void
    {
        $estimator = new RiskAndEffortEstimator();
        $quiet = $this->stage(StagedResolution::FEASIBLE_WITH_CHANGES);
        $changed = $this->stage(
            StagedResolution::FEASIBLE_WITH_CHANGES,
            new PackageChange('vendor/package', 'upgraded', '1.0.0', '2.0.0')
        );
        $blocked = $this->stage(StagedResolution::BLOCKED);
        $highFinding = new CompatibilityFinding('fixture', 'high', 'Replace the removed helper.', ['docs-1']);
        $mediumFinding = new CompatibilityFinding('fixture', 'medium', 'Review the helper.', ['docs-1']);

        self::assertSame('low', $estimator->estimateStageRisk($quiet, [], [], [])->level());
        self::assertSame('medium', $estimator->estimateStageRisk($changed, [], [], [])->level());
        self::assertSame('high', $estimator->estimateStageRisk($blocked, [], [], [])->level());
        self::assertSame('high', $estimator->estimateStageRisk($quiet, [], [$highFinding], [])->level());

        $mediumRisk = $estimator->estimateStageRisk($quiet, [], [$mediumFinding], []);

        self::assertSame('medium', $mediumRisk->level());
        self::assertSame([], $mediumRisk->drivers());
    }

    public function testTheStageAndWholeUpgradeRuleSetsDeliberatelyDisagree(): void
    {
        $estimator = new RiskAndEffortEstimator();
        $change = new PackageChange('vendor/package', 'upgraded', '1.0.0', '2.0.0');
        $finding = new CompatibilityFinding('fixture', 'high', 'Replace the removed helper.', ['docs-1']);
        $stage = $this->stage(StagedResolution::FEASIBLE_WITH_CHANGES, $change);

        self::assertSame('low', $estimator->estimateRisk([], [$change], [$finding])->level());
        self::assertSame('high', $estimator->estimateStageRisk($stage, [], [$finding], [])->level());
        self::assertSame([1, 3], $estimator->estimateEffort([], [], [], [])->components()['source_changes']);
        self::assertSame([0, 0], $estimator->estimateStageEffort($this->stage(
            StagedResolution::FEASIBLE_WITH_CHANGES
        ), [], [], [])->components()['source_changes']);
    }

    public function testEffortComponentsStayInsideTheirNamedBounds(): void
    {
        $estimator = new RiskAndEffortEstimator();
        $baseline = $estimator->estimateEffort([], [], [], []);
        $saturated = $estimator->estimateEffort([], $this->changes(30), [], $this->findings(20));
        $blocked = $estimator->estimateEffort(
            [new Blocker('conflict', 'vendor/package', 'Blocked.', 'high', ['solver-1'])],
            [],
            [],
            []
        );

        self::assertSame([1, 2], $baseline->components()['dependency_resolution']);
        self::assertSame([1, 3], $baseline->components()['source_changes']);
        self::assertSame([2, 8], $baseline->components()['tests_and_debugging']);
        self::assertSame([4, 13], $baseline->rangeHours());

        self::assertSame([1, 8], $saturated->components()['dependency_resolution']);
        self::assertSame([1, 16], $saturated->components()['source_changes']);
        self::assertSame([4, 32], $saturated->rangeHours());

        self::assertSame([3, 8], $blocked->components()['dependency_resolution']);
        self::assertSame([6, 19], $blocked->rangeHours());
    }

    /** @return list<PackageChange> */
    private function changes(int $count): array
    {
        $changes = [];
        for ($index = 1; $index <= $count; ++$index) {
            $changes[] = new PackageChange('vendor/package-' . $index, 'upgraded', '1.0.0', '2.0.0');
        }

        return $changes;
    }

    /** @return list<CompatibilityFinding> */
    private function findings(int $count): array
    {
        $findings = [];
        for ($index = 1; $index <= $count; ++$index) {
            $findings[] = new CompatibilityFinding('fixture', 'medium', 'Review finding ' . $index . '.', ['docs-1']);
        }

        return $findings;
    }

    private function stage(string $resolutionStatus, ?PackageChange $change = null): StageAnalysis
    {
        return new StageAnalysis(
            new FrameworkStageTarget(
                'fixture-1-to-2',
                'fixture',
                1,
                2,
                new UpgradeTargetSet([new UpgradeTarget('vendor/package', '^2.0')], '8.3.0'),
                '8.3.0',
                [],
                [],
                ['target-evidence']
            ),
            StageAnalysis::EXECUTED,
            $resolutionStatus,
            [],
            null,
            null,
            null,
            null,
            $change === null ? [] : [$change]
        );
    }
}
