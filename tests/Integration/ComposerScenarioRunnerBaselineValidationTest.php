<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Integration;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Tests\Support\FixtureSnapshot;
use PHPUnit\Framework\TestCase;

final class ComposerScenarioRunnerBaselineValidationTest extends TestCase
{
    public function testComposerRejectsAnOutdatedBaselineLockAsAValidationFailure(): void
    {
        if (!$this->composerIsAvailable()) {
            self::markTestSkipped('Composer is required for the baseline-validation integration test.');
        }

        $projectPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $snapshot = FixtureSnapshot::capture($projectPath);
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);
        $scenario = new Scenario('baseline-validation', $request->targets(), false, false, true);

        $result = (new ComposerScenarioRunner())->run($project, $request, $scenario);

        self::assertFalse($result->succeeded());
        self::assertSame(ScenarioResult::FAILURE_VALIDATION, $result->failureType());
        self::assertSame(ScenarioResult::FAILURE_VALIDATION, $result->toArray()['failure_type']);
        self::assertStringContainsString('lock file', strtolower($result->stdout() . $result->stderr()));
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
