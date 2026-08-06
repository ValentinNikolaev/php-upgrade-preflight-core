<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\DefaultUpgradeAnalyzer;
use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Tests\Support\FixtureSnapshot;
use PHPUnit\Framework\TestCase;

final class DefaultUpgradeAnalyzerIsolationTest extends TestCase
{
    public function testAnalysisLeavesEveryOriginalProjectFileByteForByteUnchanged(): void
    {
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $snapshot = FixtureSnapshot::capture($projectPath);
        $workingDirectories = [];

        $scenarioRunner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $workingDirectory) use (&$workingDirectories): array {
                $workingDirectories[] = $workingDirectory;
                file_put_contents($workingDirectory . DIRECTORY_SEPARATOR . 'composer.lock', json_encode([
                    'packages' => [[
                        'name' => 'fixture/dependency',
                        'version' => '2.0.0',
                    ]],
                    'packages-dev' => [],
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
                file_put_contents($workingDirectory . DIRECTORY_SEPARATOR . 'scenario-output.txt', implode(' ', $command));

                return [
                    'exit_code' => 0,
                    'stdout' => 'Deterministic Composer simulation completed.',
                    'stderr' => '',
                ];
            }
        );
        $analyzer = new DefaultUpgradeAnalyzer([], null, $scenarioRunner);
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            '8.0',
            '8.1',
            ['src']
        );

        $report = $analyzer->analyzeUpgrade($request);

        $snapshot->assertUnchanged($this);
        self::assertCount(3, $report->scenarios());
        self::assertCount(1, $report->lockDiff()->packageChanges());
        self::assertCount(3, $workingDirectories);
        self::assertCount(3, array_unique($workingDirectories));

        foreach ($workingDirectories as $workingDirectory) {
            self::assertNotSame($projectPath, $workingDirectory);
            self::assertDirectoryDoesNotExist($workingDirectory);
        }
    }
}
