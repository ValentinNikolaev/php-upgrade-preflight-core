<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\DefaultUpgradeAnalyzer;
use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Framework\CompatibilityRule;
use PhpUpgradePreflight\Core\Framework\FrameworkDetection;
use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class DefaultUpgradeAnalyzerTest extends TestCase
{
    public function testCombinedPhpAndPackageTargetsAddPlatformOnlyAndStagedScenarios(): void
    {
        $capturedUpdates = [];
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory) use (&$capturedUpdates): array {
            if ($command[1] === 'validate') {
                return ['exit_code' => 0, 'stdout' => 'Valid.', 'stderr' => ''];
            }

            $capturedUpdates[] = [
                'command' => $command,
                'composer' => json_decode((string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'), true, 512, JSON_THROW_ON_ERROR),
            ];

            return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], '8.0', '8.1');

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);

        self::assertSame(
            [
                'baseline-validation',
                'exact-target',
                'target-with-all-dependencies',
                'minimal-changes',
                'target-platform-only',
                'staged-targets',
            ],
            array_map(static fn (ScenarioResult $result): string => $result->scenario()->name(), $report->scenarios())
        );
        self::assertCount(5, $capturedUpdates);

        $platformOnly = $capturedUpdates[3];
        self::assertSame('1.0.0', $platformOnly['composer']['require']['fixture/dependency']);
        self::assertSame('8.1.0', $platformOnly['composer']['config']['platform']['php']);
        self::assertNotContains('fixture/dependency', $platformOnly['command']);

        $stagedTargets = $capturedUpdates[4];
        self::assertSame('^2.0', $stagedTargets['composer']['require']['fixture/dependency']);
        self::assertSame('8.0.0', $stagedTargets['composer']['config']['platform']['php']);
        self::assertContains('fixture/dependency', $stagedTargets['command']);
        self::assertContains('--with-all-dependencies', $stagedTargets['command']);
        self::assertFalse($report->scenarios()[4]->scenario()->determinesTargetFeasibility());
        self::assertFalse($report->scenarios()[5]->scenario()->determinesTargetFeasibility());
        self::assertTrue($report->scenarios()[4]->scenario()->isPartialTargetProbe());
        self::assertTrue($report->scenarios()[5]->scenario()->isPartialTargetProbe());
    }

    public function testSuccessfulPartialStagesDoNotMakeABlockedCombinedTargetFeasible(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory): array {
            if ($command[1] === 'validate') {
                return ['exit_code' => 0, 'stdout' => 'Valid.', 'stderr' => ''];
            }

            $composer = json_decode((string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $hasPackageTarget = $composer['require']['fixture/dependency'] === '^2.0';
            $platformPhp = $composer['config']['platform']['php'] ?? null;
            $hasPhpTarget = $platformPhp === '8.1.0';

            if (!$hasPackageTarget || !$hasPhpTarget) {
                file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', json_encode([
                    'packages' => [['name' => 'fixture/dependency', 'version' => $hasPackageTarget ? '2.0.0' : '1.0.0']],
                    'packages-dev' => [],
                ], JSON_THROW_ON_ERROR));

                return ['exit_code' => 0, 'stdout' => 'Partial stage resolved.', 'stderr' => ''];
            }

            return [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => "Your requirements could not be resolved to an installable set of packages.\n- Root composer.json requires fixture/dependency ^2.0.",
            ];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], '8.0', '8.1');

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);

        self::assertTrue($report->scenarios()[4]->succeeded());
        self::assertTrue($report->scenarios()[5]->succeeded());
        self::assertSame('blocked', $report->resolutionStatus());
        self::assertSame([], $report->lockDiff()->packageChanges());
        self::assertCount(3, $report->blockers());
    }

    public function testPartialScenariosAreSkippedForPhpOnlyTargets(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 0,
            'stdout' => 'Resolved.',
            'stderr' => '',
        ]);
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('php', '8.1')], '8.0');

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);

        self::assertSame(
            ['baseline-validation', 'exact-target', 'target-with-all-dependencies', 'minimal-changes'],
            array_map(static fn (ScenarioResult $result): string => $result->scenario()->name(), $report->scenarios())
        );
    }

    public function testStagedScenarioIsSkippedWhenTheCurrentPhpVersionIsUnknown(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 0,
            'stdout' => 'Resolved.',
            'stderr' => '',
        ]);
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], null, '8.1');

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);
        $scenarioNames = array_map(
            static fn (ScenarioResult $result): string => $result->scenario()->name(),
            $report->scenarios()
        );

        self::assertContains('target-platform-only', $scenarioNames);
        self::assertNotContains('staged-targets', $scenarioNames);
        self::assertContains(
            'The staged package-target scenario was skipped because the current project PHP version is unknown; supply --from-php or configure config.platform.php.',
            $report->uncertainties()
        );
    }

    public function testStagedScenarioUsesTheProjectComposerPlatformWhenFromPhpIsOmitted(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 0,
            'stdout' => 'Resolved.',
            'stderr' => '',
        ]);
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], null, '8.1');

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);
        $staged = array_values(array_filter(
            $report->scenarios(),
            static fn (ScenarioResult $result): bool => $result->scenario()->name() === 'staged-targets'
        ));

        self::assertCount(1, $staged);
        self::assertSame('8.0.30', $staged[0]->scenario()->targets()->targetPhp());
    }

    public function testBaselineOperationalFailureStillProducesEnvironmentRemediation(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (array $command): array {
            if ($command[1] === 'validate') {
                throw new \RuntimeException('Composer validation could not start.');
            }

            return [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => "Your requirements could not be resolved to an installable set of packages.\n- Root composer.json requires fixture/dependency ^2.0.",
            ];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);

        self::assertTrue($report->scenarios()[0]->isOperationalFailure());
        self::assertContains(
            'Restore the Composer analysis environment so every scenario can complete.',
            $report->planStages()[1]->actions()
        );
    }

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
        self::assertSame([], $report->blockers());
        self::assertSame('low', $report->risk()->level());
        self::assertCount(1, $report->lockDiff()->packageChanges());
        self::assertSame('2.0.0', $report->lockDiff()->packageChanges()[0]->toVersion());
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
        self::assertSame([], $report->blockers());
        self::assertSame('low', $report->risk()->level());
        self::assertCount(6, $report->uncertainties());
        self::assertStringContainsString('"baseline-validation"', $report->uncertainties()[0]);
        self::assertStringContainsString('analysis-environment failure', $report->uncertainties()[0]);
    }

    public function testSuccessfulBaselineDoesNotMaskBlockedTargetScenarios(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (array $command): array {
            if ($command[1] === 'validate') {
                return ['exit_code' => 0, 'stdout' => 'Valid.', 'stderr' => ''];
            }

            return [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => "Your requirements could not be resolved to an installable set of packages.\n- Root composer.json requires fixture/dependency ^2.0.",
            ];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], null, null, ['src']);

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);

        self::assertSame('blocked', $report->resolutionStatus());
        self::assertCount(4, $report->scenarios());
        self::assertTrue($report->scenarios()[0]->succeeded());
        self::assertCount(3, $report->blockers());
    }

    public function testInvalidBaselineDoesNotProduceEnvironmentRemediationForTargetBlockers(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (array $command): array {
            if ($command[1] === 'validate') {
                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => 'The lock file is not up to date with the latest changes in composer.json.',
                ];
            }

            return [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => "Your requirements could not be resolved to an installable set of packages.\n- Root composer.json requires fixture/dependency ^2.0.",
            ];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], null, null, ['src']);

        $report = (new DefaultUpgradeAnalyzer([], null, $runner))->analyzeUpgrade($request);
        $dependencyActions = $report->planStages()[1]->actions();

        self::assertSame(ScenarioResult::FAILURE_VALIDATION, $report->scenarios()[0]->failureType());
        self::assertSame(ScenarioResult::FAILURE_VALIDATION, $report->toArray()['resolution']['scenarios'][0]['failure_type']);
        self::assertNotContains('Restore the Composer analysis environment so every scenario can complete.', $dependencyActions);
        self::assertContains('Rerun the isolated Composer scenarios after resolving the reported blockers.', $dependencyActions);
        self::assertStringContainsString('baseline validation did not pass', $report->uncertainties()[0]);
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
        self::assertCount(1, $report->blockers());
        self::assertCount(5, $report->uncertainties());
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

    public function testDetectedFrameworkFindingAndEvidenceReachTheAssembledReport(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (): array {
            return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);
        $framework = new AnalyzerFixtureFrameworkIntegration();

        $report = (new DefaultUpgradeAnalyzer([$framework], null, $runner))->analyzeUpgrade($request);

        self::assertSame(1, $framework->detectionCount);
        self::assertCount(1, $report->frameworkFindings());
        self::assertSame('Detected framework requires review.', $report->frameworkFindings()[0]->summary());
        self::assertSame(['framework-1'], $report->frameworkFindings()[0]->evidence());
        self::assertCount(3, $report->evidence());
        self::assertSame('framework-1', $report->evidence()[0]->id());
        self::assertSame(Evidence::E2_PACKAGE_METADATA, $report->evidence()[0]->evidenceClass());
        self::assertSame(['framework-1'], $report->toArray()['framework_findings'][0]['evidence']);
    }
}

final class AnalyzerFixtureFrameworkIntegration implements FrameworkIntegration
{
    public int $detectionCount = 0;

    public function name(): string
    {
        return 'fixture';
    }

    public function detect(ProjectState $project): FrameworkDetection
    {
        ++$this->detectionCount;

        return new FrameworkDetection($this->name(), true, '1.0.0');
    }

    public function rules(): iterable
    {
        yield new AnalyzerFixtureCompatibilityRule();
    }

    public function defaultSourcePaths(ProjectState $project): array
    {
        return ['src'];
    }
}

final class AnalyzerFixtureCompatibilityRule implements CompatibilityRule
{
    public function evaluate(ProjectState $project, UpgradeRequest $request, EvidenceLedger $evidence): CompatibilityFinding
    {
        $evidenceId = $evidence->add(
            'framework',
            Evidence::E2_PACKAGE_METADATA,
            'Detected fixture framework metadata.'
        )->id();

        return new CompatibilityFinding('fixture', 'medium', 'Detected framework requires review.', [$evidenceId]);
    }
}
