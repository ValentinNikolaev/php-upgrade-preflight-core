<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\ReportAssembler;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
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
}
