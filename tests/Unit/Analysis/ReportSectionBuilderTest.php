<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\ReportSectionBuilder;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\PlanStage;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\RootConstraintChange;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class ReportSectionBuilderTest extends TestCase
{
    public function testItBuildsEvidenceBackedConstraintPlanTestAndUncertaintySections(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [
            new UpgradeTarget('vendor/package', '^2.0'),
            new UpgradeTarget('new/package', '^1.0'),
        ], null, '8.2');
        $project = new ProjectState($projectPath, new ComposerJson([
            'require' => [
                'php' => '^8.0',
                'vendor/package' => '^1.0',
            ],
            'scripts' => ['test' => 'phpunit'],
        ]), new ComposerLock([]));
        $evidence = new EvidenceLedger([
            new Evidence('source-1', Evidence::E3_PROJECT_SOURCE, 'Detected source usage.'),
            new Evidence('framework-1', Evidence::E2_PACKAGE_METADATA, 'Detected framework issue.'),
        ]);
        $sourceImpact = [new SourceUsage('src/Example.php', 'Vendor\\Package', 'namespace_import', ['source-1'], 4)];
        $frameworkFindings = [new CompatibilityFinding('fixture', 'medium', 'Review the framework integration.', ['framework-1'])];
        $scenario = new Scenario('exact-target', $request->targets());

        $sections = (new ReportSectionBuilder())->build(
            $request,
            $project,
            [new ScenarioResult($scenario, 0, 'Resolved.', '', new ComposerLock([]))],
            new LockDiff([new PackageChange('vendor/package', 'upgraded', '1.0.0', '2.0.0', true)]),
            [],
            $sourceImpact,
            $frameworkFindings,
            [],
            $evidence
        );

        self::assertSame(['new/package', 'vendor/package'], array_map(
            static fn (RootConstraintChange $change): string => $change->package(),
            $sections->rootConstraintChanges()
        ));
        self::assertSame(['added', 'updated'], array_map(
            static fn (RootConstraintChange $change): string => $change->changeType(),
            $sections->rootConstraintChanges()
        ));
        self::assertSame(['constraints', 'dependencies', 'application', 'validation'], array_map(
            static fn (PlanStage $stage): string => $stage->name(),
            $sections->planStages()
        ));
        self::assertSame('composer test', $sections->tests()[1]->command());
        self::assertCount(4, $sections->tests());
        self::assertSame(
            ['Regenerate `composer.lock` with the smallest successful dependency transition.'],
            $sections->planStages()[1]->actions()
        );
        self::assertSame([
            'Dependency resolution does not prove application runtime compatibility; the project test suite must run on the target runtime.',
        ], $sections->uncertainties());
        self::assertCount(5, $evidence->all());
    }

    public function testItReportsOperationalFailuresAndAnUnknownTestCommand(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('vendor/package', '^1.0')]);
        $project = new ProjectState($projectPath, new ComposerJson([
            'require' => ['vendor/package' => '^1.0'],
        ]), new ComposerLock([]));
        $scenario = new Scenario('exact-target', $request->targets());
        $evidence = new EvidenceLedger();

        $sections = (new ReportSectionBuilder())->build(
            $request,
            $project,
            [new ScenarioResult($scenario, 1, '', 'Unavailable.', null, null, ScenarioResult::FAILURE_OPERATIONAL)],
            new LockDiff([]),
            [],
            [],
            [],
            ['Source scan incomplete.'],
            $evidence
        );

        self::assertSame([], $sections->rootConstraintChanges());
        self::assertNull($sections->tests()[1]->command());
        self::assertSame(
            ['Restore the Composer analysis environment and rerun the isolated scenarios before changing the lockfile.'],
            $sections->planStages()[1]->actions()
        );
        self::assertSame([
            'Source scan incomplete.',
            'Composer scenario "exact-target" could not complete because of an analysis-environment failure.',
            'Dependency resolution does not prove application runtime compatibility; the project test suite must run on the target runtime.',
            'No Composer "test" script was found, so the project\'s canonical test command is unknown.',
        ], $sections->uncertainties());
        self::assertSame('plan-1', $evidence->all()[0]->id());
    }

    public function testPhpPlatformTargetDoesNotBecomeAnExactRootConstraint(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [], null, '8.1');
        $project = new ProjectState($projectPath, new ComposerJson([
            'require' => ['php' => '^7.4'],
            'scripts' => ['test' => 'phpunit'],
        ]), new ComposerLock([]));
        $scenario = new Scenario('target-platform', $request->targets());
        $evidence = new EvidenceLedger();

        $sections = (new ReportSectionBuilder())->build(
            $request,
            $project,
            [new ScenarioResult($scenario, 0, 'Resolved.', '', new ComposerLock([]))],
            new LockDiff([]),
            [],
            [],
            [],
            [],
            $evidence
        );

        self::assertSame([], $sections->rootConstraintChanges());
        self::assertSame(
            ['Select a root PHP constraint that includes target platform PHP 8.1.0 without pinning an exact patch version.'],
            $sections->planStages()[0]->actions()
        );
        self::assertSame(
            ['Keep the existing lockfile after Composer confirms that no package changes are required.'],
            $sections->planStages()[1]->actions()
        );
        self::assertContains(
            'Root PHP constraint "^7.4" does not include target platform PHP 8.1.0; select an appropriate Composer constraint instead of using the exact simulated platform version.',
            $sections->uncertainties()
        );
        self::assertSame(['plan-1'], array_map(
            static fn (Evidence $item): string => $item->id(),
            $evidence->all()
        ));
    }

    public function testSuccessfulResolutionTreatsAbandonedPackagesAsMaintenanceAdvisories(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('vendor/package', '^2.0')]);
        $project = new ProjectState($projectPath, new ComposerJson([
            'require' => ['vendor/package' => '^1.0'],
            'scripts' => ['test' => 'phpunit'],
        ]), new ComposerLock([]));
        $scenario = new Scenario('exact-target', $request->targets());
        $evidence = new EvidenceLedger([
            new Evidence('lock-metadata-1', Evidence::E2_PACKAGE_METADATA, 'Package is abandoned.'),
        ]);
        $advisory = new Blocker(
            'abandoned-package',
            'vendor/legacy',
            'Abandoned.',
            'high',
            ['lock-metadata-1']
        );

        $sections = (new ReportSectionBuilder())->build(
            $request,
            $project,
            [new ScenarioResult($scenario, 0, 'Resolved.', '', new ComposerLock([]))],
            new LockDiff([new PackageChange('vendor/package', 'upgraded', '1.0.0', '2.0.0')]),
            [$advisory],
            [],
            [],
            [],
            $evidence
        );

        self::assertSame(
            'Address dependency maintenance advisories in the feasible dependency state.',
            $sections->planStages()[1]->summary()
        );
        self::assertSame([
            'Address the `abandoned-package` advisory affecting `vendor/legacy`.',
            'Apply and review the smallest successful dependency transition before addressing maintenance advisories.',
        ], $sections->planStages()[1]->actions());
        self::assertNotContains(
            'Rerun the isolated Composer scenarios after resolving the reported blockers.',
            $sections->planStages()[1]->actions()
        );
    }
}
