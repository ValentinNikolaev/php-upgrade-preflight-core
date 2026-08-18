<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\ReportAssembler;
use PhpUpgradePreflight\Core\Analysis\SourceImpactBuilder;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Source\SymbolOwnershipIndex;
use PHPUnit\Framework\TestCase;

final class ReportAssemblerTest extends TestCase
{
    public function testItAggregatesOperationalAndSourceUncertaintiesIntoAValidatedReport(): void
    {
        $request = new UpgradeRequest(__DIR__, [new UpgradeTarget('vendor/package', '^2.0')]);
        $project = new ProjectState(__DIR__, new ComposerJson([]), new ComposerLock([]));
        $scenario = new Scenario('exact-target', $request->targets());
        $scenarioResult = new ScenarioResult(
            $scenario,
            1,
            '',
            'Composer unavailable.',
            null,
            null,
            ScenarioResult::FAILURE_OPERATIONAL
        );
        $evidence = new EvidenceLedger();
        $evidenceId = $evidence->add('solver', Evidence::E1_SOLVER, 'Solver conflict.')->id();

        $report = (new ReportAssembler())->assemble(
            $request,
            $project,
            [$scenarioResult],
            new LockDiff([]),
            [new Blocker('conflict', 'vendor/package', 'Blocked.', 'high', [$evidenceId])],
            [],
            [],
            [],
            new RiskSummary('high', ['Composer resolution is blocked.']),
            new EffortEstimate([6, 19], 'low', [], []),
            ['Source path was unavailable.', 'Source path was unavailable.'],
            $evidence
        );

        self::assertSame($request, $report->request());
        self::assertSame($project, $report->projectState());
        self::assertSame([
            'Source path was unavailable.',
            'Composer scenario "exact-target" could not complete because of an analysis-environment failure.',
            'Dependency resolution does not prove application runtime compatibility; the project test suite must run on the target runtime.',
            'No Composer "test" script was found, so the project\'s canonical test command is unknown.',
            'Composer extension checks used the analyzer runtime because no complete explicit extension platform was supplied.',
            'Compatible Composer execution may inherit global configuration, credentials, proxies, caches, repository access, and other analyzer-host state.',
        ], $report->uncertainties());
        self::assertSame($evidenceId, $report->evidence()[0]->id());
        self::assertCount(1, $report->rootConstraintChanges());
        self::assertCount(3, $report->planStages());
        self::assertSame([
            'Resolve the `conflict` blocker affecting `vendor/package`.',
            'Restore the Composer analysis environment so every scenario can complete.',
            'Rerun the isolated Composer scenarios after resolving the reported blockers.',
        ], $report->planStages()[1]->actions());
        self::assertCount(3, $report->tests());
    }

    public function testItCarriesTheCorrelatedSourceImpactSuppliedByTheCaller(): void
    {
        $request = new UpgradeRequest(__DIR__, [new UpgradeTarget('vendor/package', '^2.0')]);
        $project = new ProjectState(__DIR__, new ComposerJson([
            'require' => ['vendor/package' => '^1.0'],
        ]), new ComposerLock([]));
        $scenario = new Scenario('exact-target', $request->targets());
        $scenarioResult = new ScenarioResult($scenario, 0, 'Resolved.', '', new ComposerLock([]));
        $evidence = new EvidenceLedger([
            new Evidence('source-1', Evidence::E3_PROJECT_SOURCE, 'Detected source usage.'),
        ]);
        $inventory = [new SourceUsage(
            'src/Client.php',
            'Vendor\\Package\\Client',
            'instantiated_class',
            ['source-1'],
            12
        )];
        $change = new PackageChange('vendor/package', 'upgraded', '1.0.0', '2.0.0', true);
        $ownership = new SymbolOwnershipIndex();
        $ownership->addPrefix('Vendor\\Package\\', 'vendor/package', 'psr-4');

        $report = (new ReportAssembler())->assemble(
            $request,
            $project,
            [$scenarioResult],
            new LockDiff([$change]),
            [],
            $inventory,
            (new SourceImpactBuilder())->build($inventory, [], [$change], $ownership, $evidence),
            [],
            new RiskSummary('medium', ['A root dependency changes across a major version.']),
            new EffortEstimate([2, 6], 'low', [], []),
            [],
            $evidence
        );

        self::assertCount(1, $report->actionableSourceImpact());
        $finding = $report->actionableSourceImpact()[0];
        self::assertSame('vendor/package', $finding->affectedPackage());
        self::assertSame('exact', $finding->ownership());
        self::assertSame('package_change', $finding->relevance());
        $ownershipEvidence = array_values(array_filter(
            $report->evidence(),
            static fn (Evidence $item): bool => str_starts_with($item->id(), 'ownership-')
        ));
        self::assertCount(1, $ownershipEvidence);
        self::assertSame(Evidence::E2_PACKAGE_METADATA, $ownershipEvidence[0]->evidenceClass());
        self::assertContains($ownershipEvidence[0]->id(), $finding->evidence());
    }

    public function testItBuildsATerminalReportForUnreadableComposerInput(): void
    {
        $request = new UpgradeRequest(__DIR__, [new UpgradeTarget('vendor/package', '^2.0')]);
        $project = new ProjectState(__DIR__, new ComposerJson([]), new ComposerLock([]));
        $scenarioResult = new ScenarioResult(
            new Scenario('project-input', $request->targets(), false),
            1,
            '',
            'composer.lock is missing.',
            null,
            null,
            ScenarioResult::FAILURE_OPERATIONAL,
            null,
            [],
            0,
            null,
            [],
            ScenarioResult::OUTCOME_LOCKFILE_MISSING
        );

        $report = ReportAssembler::inputFailure($request, $project, $scenarioResult, 'composer.lock is missing.');

        self::assertSame('unknown', $report->resolutionStatus());
        self::assertSame([], $report->planStages());
        self::assertSame([], $report->tests());
        self::assertSame([], $report->evidence());
        self::assertContains(
            'Composer project input could not be loaded: composer.lock is missing.',
            $report->uncertainties()
        );
        self::assertSame('project_input_failure', $report->stagedResolution()->stopReason());
    }
}
