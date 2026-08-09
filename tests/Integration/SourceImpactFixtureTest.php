<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Integration;

use PhpUpgradePreflight\Core\Analysis\RiskAndEffortEstimator;
use PhpUpgradePreflight\Core\Analysis\SourceImpactBuilder;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Source\AutoloadOwnershipIndexBuilder;
use PhpUpgradePreflight\Core\Source\SourceUsageScanner;
use PHPUnit\Framework\TestCase;

final class SourceImpactFixtureTest extends TestCase
{
    public function testActionableImpactUsesOwnershipAndChangedPackagesWithoutCountingApplicationNamespaces(): void
    {
        $projectPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'source-impact';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $evidence = new EvidenceLedger();
        $uncertainties = [];
        $inventory = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties);
        $ownership = (new AutoloadOwnershipIndexBuilder())->build(
            $project,
            $uncertainties,
            array_map(static fn ($usage): string => $usage->symbol(), $inventory)
        );
        $impact = (new SourceImpactBuilder())->build(
            $inventory,
            [],
            [
                new PackageChange('fixture/changed', 'upgraded', '1.0.0', '2.0.0', true),
                new PackageChange('fixture/ambiguous-b', 'removed', '1.0.0', null),
                new PackageChange('fixture/classmap', 'upgraded', '1.0.0', '1.1.0'),
                new PackageChange('fixture/removed', 'removed', '1.0.0', null),
            ],
            $ownership,
            $evidence
        );

        self::assertGreaterThan(12, count($inventory));
        self::assertSame([], $uncertainties);
        self::assertSame(
            ['fixture/changed', 'fixture/ambiguous-b', 'fixture/classmap', 'fixture/removed'],
            array_map(static fn ($finding): ?string => $finding->affectedPackage(), $impact)
        );
        self::assertSame(['exact', 'ambiguous', 'exact', 'exact'], array_map(
            static fn ($finding): string => $finding->ownership(),
            $impact
        ));
        self::assertCount(2, $impact[0]->occurrences());
        self::assertSame([7, 8], array_map(static fn ($usage): ?int => $usage->line(), $impact[0]->occurrences()));
        self::assertSame('Legacy\\Client', $impact[2]->occurrences()[0]->symbol());
        self::assertSame('high', $impact[3]->severity());
        self::assertNotContains('fixture/unchanged', array_map(
            static fn ($finding): ?string => $finding->affectedPackage(),
            $impact
        ));
        foreach ($impact as $finding) {
            foreach ($finding->occurrences() as $occurrence) {
                self::assertStringStartsNotWith('App\\', $occurrence->symbol());
                self::assertNotEmpty($occurrence->evidence());
            }
        }

        $estimator = new RiskAndEffortEstimator();
        self::assertSame('low', $estimator->estimateRisk([], [], [])->level());
        self::assertSame('high', $estimator->estimateRisk([], [], [], $impact)->level());
        self::assertSame([1, 16], $estimator->estimateEffort([], [], $impact, [])->components()['source_changes']);
    }
}
