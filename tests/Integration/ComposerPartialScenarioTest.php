<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Integration;

use PhpUpgradePreflight\Core\Analysis\DefaultUpgradeAnalyzer;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Tests\Support\FixtureSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ComposerPartialScenarioTest extends TestCase
{
    public function testPlatformOnlyAndStagedScenariosResolveOfflineWithoutMutatingTheProject(): void
    {
        if (!$this->composerIsAvailable()) {
            self::markTestSkipped('Composer is required for the partial-scenario integration test.');
        }

        $projectPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'path-repository' . DIRECTORY_SEPARATOR . 'project';
        $snapshot = FixtureSnapshot::capture(dirname($projectPath));
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('fixture/dependency', '^1.0')],
            '8.0',
            '8.1'
        );

        $report = (new DefaultUpgradeAnalyzer())->analyzeUpgrade($request);
        $results = [];
        foreach ($report->scenarios() as $result) {
            $results[$result->scenario()->name()] = $result;
        }

        self::assertArrayHasKey('target-platform-only', $results);
        self::assertArrayHasKey('staged-targets', $results);
        self::assertTrue($results['target-platform-only']->succeeded(), $results['target-platform-only']->stderr());
        self::assertTrue($results['staged-targets']->succeeded(), $results['staged-targets']->stderr());
        self::assertFalse($results['target-platform-only']->scenario()->determinesTargetFeasibility());
        self::assertFalse($results['staged-targets']->scenario()->determinesTargetFeasibility());
        $snapshot->assertUnchanged($this);
    }

    private function composerIsAvailable(): bool
    {
        $process = new Process(['composer', '--version']);
        $process->run();

        return $process->isSuccessful();
    }
}
