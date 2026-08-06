<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\DefaultUpgradeAnalyzer;
use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class DefaultUpgradeAnalyzerTest extends TestCase
{
    public function testSuccessfulFallbackRemovesBlockersAndPrefersMinimalChanges(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory): array {
            if (!in_array('--with-all-dependencies', $command, true)) {
                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => "Your requirements could not be resolved to an installable set of packages.\n- Root composer.json requires fixture/dependency ^2.0.",
                ];
            }

            $version = in_array('--minimal-changes', $command, true) ? '2.0.0' : '3.0.0';
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', json_encode([
                'packages' => [['name' => 'fixture/dependency', 'version' => $version]],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR));

            return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], null, null, ['src']);

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);

        self::assertSame('feasible_with_changes', $report->resolutionStatus());
        self::assertSame([], $report->blockers);
        self::assertSame('low', $report->risk->level);
        self::assertCount(1, $report->lockDiff->packageChanges);
        self::assertSame('2.0.0', $report->lockDiff->packageChanges[0]->toVersion);
    }

    public function testOperationalFailuresProduceUnknownResolutionAndUncertainties(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (): array {
            throw new \RuntimeException('Composer executable is unavailable.');
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], null, null, ['src']);

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);

        self::assertSame('unknown', $report->resolutionStatus());
        self::assertSame([], $report->blockers);
        self::assertSame('low', $report->risk->level);
        self::assertCount(3, $report->uncertainties);
        self::assertStringContainsString('analysis-environment failure', $report->uncertainties[0]);
    }

    public function testOperationalFallbackFailuresKeepTheOverallResolutionUnknown(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (array $command): array {
            if (!in_array('--with-all-dependencies', $command, true)) {
                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => "Your requirements could not be resolved to an installable set of packages.\n- Root composer.json requires fixture/dependency ^2.0.",
                ];
            }

            throw new \RuntimeException('Composer could not complete the fallback scenario.');
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], null, null, ['src']);

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);

        self::assertSame('unknown', $report->resolutionStatus());
        self::assertCount(1, $report->blockers);
        self::assertCount(2, $report->uncertainties);
    }

    public function testUnavailableRequestedFrameworkIsRejected(): void
    {
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            ['src'],
            ['missing-framework']
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing-framework');

        (new DefaultUpgradeAnalyzer())->analyzeUpgrade($request);
    }
}
