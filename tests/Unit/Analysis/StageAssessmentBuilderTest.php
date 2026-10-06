<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\StageAssessmentBuilder;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\StageAnalysis;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PhpUpgradePreflight\Core\Source\SymbolOwnershipIndex;
use PHPUnit\Framework\TestCase;

final class StageAssessmentBuilderTest extends TestCase
{
    public function testItCorrelatesPerStageAndKeepsOneOccurrenceRegistry(): void
    {
        $path = dirname(__DIR__, 5) . '/tests/fixtures/project-isolation';
        $project = new ProjectState(
            $path,
            new ComposerJson(['require' => ['vendor/package' => '^1.0'], 'scripts' => ['test' => 'phpunit']]),
            new ComposerLock([])
        );
        $request = new UpgradeRequest($path, [new UpgradeTarget('vendor/package', '^3.0')]);
        $change = new PackageChange('vendor/package', 'upgraded', '1.0.0', '2.0.0', true);
        $stages = [
            $this->stage('fixture-1-to-2', 1, 2, $change),
            $this->stage('fixture-2-to-3', 2, 3, $change),
        ];
        $resolution = new StagedResolution(
            StagedResolution::EVALUATED,
            StagedResolution::FEASIBLE_WITH_CHANGES,
            'fixture',
            $stages,
            []
        );
        $inventory = [new SourceUsage(
            'src/Fixture.php',
            'Vendor\\Package\\Client',
            'instantiated_class',
            ['source-1'],
            12
        )];
        $findings = [
            new CompatibilityFinding('fixture', 'medium', 'Review the client.', ['source-1', 'docs-1'], [
                ['from_major' => 1, 'to_major' => 2],
                ['from_major' => 2, 'to_major' => 3],
            ]),
        ];
        $ownership = new SymbolOwnershipIndex();
        $ownership->addPrefix('Vendor\\Package\\', 'vendor/package', 'psr-4');
        $evidence = new EvidenceLedger([
            new Evidence('source-1', Evidence::E3_PROJECT_SOURCE, 'Source usage.'),
            new Evidence('docs-1', Evidence::E4_MAINTAINER_DOCUMENTATION, 'Framework guidance.'),
        ]);

        $assessed = (new StageAssessmentBuilder())->build(
            $resolution,
            $inventory,
            $findings,
            $ownership,
            $evidence,
            $project,
            $request
        )->toArray();

        self::assertCount(1, $assessed['source_impact']);
        self::assertSame(
            ['fixture-1-to-2', 'fixture-2-to-3'],
            $assessed['source_impact'][0]['stage_ids']
        );
        self::assertCount(1, $assessed['source_impact'][0]['occurrences']);
        self::assertSame(
            $assessed['stages'][0]['source_impact'],
            $assessed['stages'][1]['source_impact']
        );
        self::assertSame('fixture-1-to-2', $assessed['stages'][0]['risk']['stage_id']);
        self::assertSame('fixture-2-to-3', $assessed['stages'][1]['effort']['stage_id']);
        self::assertSame('fixture-1-to-2', $assessed['stages'][0]['tests'][0]['stage_id']);
        self::assertStringContainsString('original project source snapshot', $assessed['stages'][1]['source_snapshot_note']);
        self::assertCount(1, array_filter(
            $evidence->all(),
            static fn (Evidence $item): bool => str_starts_with($item->id(), 'ownership-')
        ));
    }

    public function testItExcludesAnotherFrameworkFindingForTheSameNumericHop(): void
    {
        $path = dirname(__DIR__, 5) . '/tests/fixtures/project-isolation';
        $project = new ProjectState(
            $path,
            new ComposerJson(['require' => ['vendor/package' => '^1.0']]),
            new ComposerLock([])
        );
        $request = new UpgradeRequest($path, [new UpgradeTarget('vendor/package', '^2.0')]);
        $resolution = new StagedResolution(
            StagedResolution::EVALUATED,
            StagedResolution::FEASIBLE_WITH_CHANGES,
            'fixture',
            [$this->stage('fixture-1-to-2', 1, 2)],
            []
        );
        $inventory = [new SourceUsage(
            'src/Foreign.php',
            'Other\\Framework\\Client',
            'instantiated_class',
            ['foreign-source'],
            24
        )];
        $findings = [new CompatibilityFinding(
            'other-framework',
            'high',
            'This finding belongs to another framework.',
            ['foreign-source'],
            [['from_major' => 1, 'to_major' => 2]]
        )];
        $evidence = new EvidenceLedger([
            new Evidence('foreign-source', Evidence::E3_PROJECT_SOURCE, 'Foreign framework usage.'),
        ]);

        $assessed = (new StageAssessmentBuilder())->build(
            $resolution,
            $inventory,
            $findings,
            new SymbolOwnershipIndex(),
            $evidence,
            $project,
            $request
        )->toArray();

        self::assertSame([], $assessed['stages'][0]['source_findings']);
        self::assertSame([], $assessed['stages'][0]['source_impact']);
        self::assertSame([], $assessed['source_impact']);
        self::assertNotContains(
            '[fixture-1-to-2] Review the original-source finding: This finding belongs to another framework.',
            $assessed['stages'][0]['recommended_actions']
        );
    }

    private function stage(string $id, int $from, int $to, ?PackageChange $change = null): StageAnalysis
    {
        return new StageAnalysis(
            new FrameworkStageTarget(
                $id,
                'fixture',
                $from,
                $to,
                new UpgradeTargetSet([new UpgradeTarget('vendor/package', '^' . $to . '.0')], '8.3.0'),
                '8.3.0',
                [],
                [],
                ['target-evidence']
            ),
            StageAnalysis::EXECUTED,
            StagedResolution::FEASIBLE_WITH_CHANGES,
            [],
            null,
            null,
            null,
            null,
            $change === null ? [] : [$change]
        );
    }
}
