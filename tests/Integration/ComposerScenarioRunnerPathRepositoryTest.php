<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Integration;

use PhpUpgradePreflight\Core\Analysis\BlockerGrouper;
use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Tests\Support\FixtureSnapshot;
use PHPUnit\Framework\TestCase;

final class ComposerScenarioRunnerPathRepositoryTest extends TestCase
{
    public function testRelativePathRepositoryResolvesOfflineInAnIsolatedWorkspace(): void
    {
        if (!$this->composerIsAvailable()) {
            self::markTestSkipped('Composer is required for the path-repository integration test.');
        }

        $projectPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'path-repository' . DIRECTORY_SEPARATOR . 'project';
        $snapshot = FixtureSnapshot::capture(dirname($projectPath));
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^1.0')]);

        $result = (new ComposerScenarioRunner())->run($project, $request, new Scenario('path-repository', $request->targets(), false));

        self::assertTrue($result->succeeded(), $result->stderr());
        self::assertNull($result->failureType());
        self::assertNotNull($result->composerVersion());
        self::assertSame('composer', $result->command()[0]);
        self::assertGreaterThanOrEqual(0, $result->durationMs());
        self::assertNotNull($result->candidateLockEvidence());
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
        $process = proc_open(['composer', '--version'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return proc_close($process) === 0;
    }
}
