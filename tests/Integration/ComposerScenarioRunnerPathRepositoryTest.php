<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Integration;

use PhpUpgradePreflight\Core\Analysis\BlockerGrouper;
use PhpUpgradePreflight\Core\Analysis\DefaultUpgradeAnalyzer;
use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Reporting\JsonReportWriter;
use PhpUpgradePreflight\Tests\Support\FixtureSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class ComposerScenarioRunnerPathRepositoryTest extends TestCase
{
    public function testPackageUpgradeProducesAnActionableTransitionInAnIsolatedWorkspace(): void
    {
        if (!$this->composerIsAvailable()) {
            self::markTestSkipped('Composer is required for the path-repository integration test.');
        }

        $projectPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'path-repository' . DIRECTORY_SEPARATOR . 'project';
        $snapshot = FixtureSnapshot::capture(dirname($projectPath));
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        $report = (new DefaultUpgradeAnalyzer())->analyzeUpgrade($request);
        $result = $report->scenarios()[1];

        self::assertSame('feasible_with_changes', $report->resolutionStatus());
        self::assertTrue($report->scenarios()[0]->succeeded(), $report->scenarios()[0]->stderr());
        self::assertTrue($result->succeeded(), $result->stderr());
        self::assertNull($result->failureType());
        self::assertNotNull($result->composerVersion());
        self::assertSame('composer', $result->command()[0]);
        self::assertGreaterThanOrEqual(0, $result->durationMs());
        self::assertNotNull($result->candidateLockEvidence());
        self::assertNotNull($result->lock());
        $changes = $report->lockDiff()->packageChanges();
        self::assertCount(1, $changes);
        self::assertSame('fixture/dependency', $changes[0]->name());
        self::assertSame('upgraded', $changes[0]->changeType());
        self::assertSame('1.0.0', $changes[0]->fromVersion());
        self::assertSame('2.0.0', $changes[0]->toVersion());
        self::assertTrue($changes[0]->isDirect());
        self::assertTrue($changes[0]->isMajorChange());
        self::assertSame([], $report->blockers());

        /** @var array<string, mixed> $json */
        $json = json_decode((new JsonReportWriter())->render($report), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('upgraded', $json['transition']['package_changes'][0]['change_type']);
        self::assertSame('1.0.0', $json['transition']['package_changes'][0]['from_version']);
        self::assertSame('2.0.0', $json['transition']['package_changes'][0]['to_version']);
        self::assertTrue($json['transition']['package_changes'][0]['direct']);
        self::assertTrue($json['transition']['package_changes'][0]['major_change']);
        $snapshot->assertUnchanged($this);
    }

    public function testUnavailablePackageTargetProducesOneActionableCanonicalBlocker(): void
    {
        if (!$this->composerIsAvailable()) {
            self::markTestSkipped('Composer is required for the path-repository integration test.');
        }

        $projectPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'path-repository' . DIRECTORY_SEPARATOR . 'project';
        $snapshot = FixtureSnapshot::capture(dirname($projectPath));
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^3.0')]);

        $report = (new DefaultUpgradeAnalyzer())->analyzeUpgrade($request);

        self::assertSame('blocked', $report->resolutionStatus());
        self::assertTrue($report->scenarios()[0]->succeeded(), $report->scenarios()[0]->stderr());
        self::assertCount(1, $report->blockers());
        self::assertSame('package-not-found', $report->blockers()[0]->type());
        self::assertSame('fixture/dependency', $report->blockers()[0]->subject());
        self::assertSame('^3.0', $report->blockers()[0]->requestedConstraint());
        self::assertSame('high', $report->blockers()[0]->confidence());
        self::assertSame(['solver-1', 'solver-2', 'solver-3'], $report->blockers()[0]->evidence());

        /** @var array<string, mixed> $json */
        $json = json_decode((new JsonReportWriter())->render($report), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('package-not-found', $json['blockers'][0]['type']);
        self::assertSame('fixture/dependency', $json['blockers'][0]['subject']);
        self::assertSame('^3.0', $json['blockers'][0]['requested_constraint']);
        self::assertSame(['solver-1', 'solver-2', 'solver-3'], $json['blockers'][0]['evidence']);
        $snapshot->assertUnchanged($this);
    }

    public function testBlockedPhpTargetCapturesLockedProhibitsDiagnostic(): void
    {
        if (!$this->composerIsAvailable()) {
            self::markTestSkipped('Composer is required for the path-repository integration test.');
        }

        $projectPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'path-repository' . DIRECTORY_SEPARATOR . 'project';
        $snapshot = FixtureSnapshot::capture(dirname($projectPath));
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('php', '9.0')]);

        $result = (new ComposerScenarioRunner())->run($project, $request, new Scenario('blocked-php-target', $request->targets(), false));

        self::assertTrue($result->isSolverFailure(), $result->stderr());
        self::assertCount(1, $result->diagnostics());
        self::assertSame([
            'composer',
            'prohibits',
            'php',
            '9.0.0',
            '--tree',
            '--locked',
            '--no-scripts',
            '--no-plugins',
            '--no-interaction',
        ], $result->diagnostics()[0]->command());
        $diagnosticOutput = $result->diagnostics()[0]->stdout() . $result->diagnostics()[0]->stderr();
        self::assertStringContainsString('fixture/dependency', $diagnosticOutput);

        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([$result], $evidence);

        self::assertNotSame([], $blockers);
        self::assertSame('php-platform-too-high', $blockers[0]->type(), $diagnosticOutput);
        self::assertSame('php', $blockers[0]->subject());
        self::assertSame('9.0.0', $blockers[0]->requestedConstraint());
        self::assertNotNull($blockers[0]->conflict());
        self::assertContains('php', $blockers[0]->dependencyPath());
        self::assertSame(['solver-1'], $blockers[0]->evidence());
        self::assertCount(1, $evidence->all());
        $snapshot->assertUnchanged($this);
    }

    private function composerIsAvailable(): bool
    {
        $composer = (new ExecutableFinder())->find('composer');
        if ($composer === null) {
            return false;
        }

        $process = new Process([$composer, '--version']);
        $process->run();

        return $process->isSuccessful();
    }
}
