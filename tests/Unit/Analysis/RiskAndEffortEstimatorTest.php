<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\RiskAndEffortEstimator;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\PackageChange;
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
            new SourceUsage('src/Example.php', 'Vendor\\Package', 'namespace_import', ['source-1']),
            new SourceUsage('src/Other.php', 'Vendor\\Package', 'static_call', ['source-2']),
        ];
        $findings = [new CompatibilityFinding('fixture', 'medium', 'Review.', ['framework-1'])];

        $risk = $estimator->estimateRisk($blockers, $changes, $findings);
        $effort = $estimator->estimateEffort($blockers, $changes, $sourceImpact, $findings);

        self::assertSame('high', $risk->level);
        self::assertSame([
            'Composer resolution is blocked.',
            'Framework compatibility findings require review.',
        ], $risk->drivers);
        self::assertSame([6, 20], $effort->rangeHours);
        self::assertSame([3, 8], $effort->components['dependency_resolution']);
        self::assertSame([1, 4], $effort->components['source_changes']);
    }

    public function testALargerTransitionProducesMediumRiskWithoutBlockers(): void
    {
        $changes = [];
        for ($index = 1; $index <= 11; ++$index) {
            $changes[] = new PackageChange('vendor/package-' . $index, 'upgraded', '1.0.0', '2.0.0');
        }

        $risk = (new RiskAndEffortEstimator())->estimateRisk([], $changes, []);

        self::assertSame('medium', $risk->level);
        self::assertSame([], $risk->drivers);
    }

    public function testMoreThanTwentyPackageChangesAddTheLargeTransitionDriver(): void
    {
        $changes = [];
        for ($index = 1; $index <= 21; ++$index) {
            $changes[] = new PackageChange('vendor/package-' . $index, 'upgraded', '1.0.0', '2.0.0');
        }

        $risk = (new RiskAndEffortEstimator())->estimateRisk([], $changes, []);

        self::assertSame('medium', $risk->level);
        self::assertSame(['Large lockfile transition.'], $risk->drivers);
    }
}
