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
        self::assertCount(5, $report->uncertainties());
        self::assertStringContainsString('analysis-environment failure', $report->uncertainties()[0]);
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
        self::assertCount(4, $report->uncertainties());
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
