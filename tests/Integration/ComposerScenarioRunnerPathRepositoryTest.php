<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Integration;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
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
