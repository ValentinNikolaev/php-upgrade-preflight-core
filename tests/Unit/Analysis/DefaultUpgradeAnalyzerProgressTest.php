<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\DefaultUpgradeAnalyzer;
use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Progress\AnalysisPhase;
use PhpUpgradePreflight\Core\Progress\AnalysisProgressEvent;
use PhpUpgradePreflight\Core\Progress\AnalysisProgressReporter;
use PhpUpgradePreflight\Core\Progress\NoOpAnalysisProgressReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DefaultUpgradeAnalyzerProgressTest extends TestCase
{
    public function testReportsStablePhaseAndScenarioOrdering(): void
    {
        $reporter = new RecordingAnalysisProgressReporter();
        $report = (new DefaultUpgradeAnalyzer(
            scenarioRunner: $this->successfulScenarioRunner(),
            progressReporter: $reporter
        ))->analyzeUpgrade($this->request());

        self::assertSame('feasible_with_changes', $report->resolutionStatus());
        self::assertSame([
            ['analysis-started', null, null, null, null],
            ['phase-started', 'project-loading', null, null, null],
            ['phase-completed', 'project-loading', null, 'succeeded', null],
            ['phase-started', 'composer-feasibility', null, null, null],
            ['scenario-started', 'composer-feasibility', 'baseline-validation', null, null],
            ['scenario-completed', 'composer-feasibility', 'baseline-validation', 'succeeded', 'success'],
            ['scenario-started', 'composer-feasibility', 'exact-target', null, null],
            ['scenario-completed', 'composer-feasibility', 'exact-target', 'succeeded', 'success'],
            ['scenario-started', 'composer-feasibility', 'target-with-all-dependencies', null, null],
            ['scenario-completed', 'composer-feasibility', 'target-with-all-dependencies', 'succeeded', 'success'],
            ['scenario-started', 'composer-feasibility', 'minimal-changes', null, null],
            ['scenario-completed', 'composer-feasibility', 'minimal-changes', 'succeeded', 'success'],
            ['phase-completed', 'composer-feasibility', null, 'succeeded', null],
            ['phase-started', 'staged-resolution', null, null, null],
            ['phase-completed', 'staged-resolution', null, 'succeeded', null],
            ['phase-started', 'source-scan', null, null, null],
            ['phase-completed', 'source-scan', null, 'succeeded', null],
            ['phase-started', 'framework-evaluation', null, null, null],
            ['phase-completed', 'framework-evaluation', null, 'succeeded', null],
            ['phase-started', 'report-assembly', null, null, null],
            ['phase-completed', 'report-assembly', null, 'succeeded', null],
            ['analysis-completed', null, null, 'succeeded', 'feasible_with_changes'],
        ], $reporter->events());
    }

    public function testReporterFailuresAreContainedAcrossTheSuccessfulLifecycle(): void
    {
        $reporter = new ThrowingAnalysisProgressReporter();
        $report = (new DefaultUpgradeAnalyzer(
            scenarioRunner: $this->successfulScenarioRunner(),
            progressReporter: $reporter
        ))->analyzeUpgrade($this->request());

        self::assertSame('feasible_with_changes', $report->resolutionStatus());
        self::assertSame([
            AnalysisProgressEvent::ANALYSIS_STARTED,
            AnalysisProgressEvent::PHASE_STARTED,
            AnalysisProgressEvent::PHASE_COMPLETED,
            AnalysisProgressEvent::PHASE_STARTED,
            AnalysisProgressEvent::SCENARIO_STARTED,
            AnalysisProgressEvent::SCENARIO_COMPLETED,
            AnalysisProgressEvent::SCENARIO_STARTED,
            AnalysisProgressEvent::SCENARIO_COMPLETED,
            AnalysisProgressEvent::SCENARIO_STARTED,
            AnalysisProgressEvent::SCENARIO_COMPLETED,
            AnalysisProgressEvent::SCENARIO_STARTED,
            AnalysisProgressEvent::SCENARIO_COMPLETED,
            AnalysisProgressEvent::PHASE_COMPLETED,
            AnalysisProgressEvent::PHASE_STARTED,
            AnalysisProgressEvent::PHASE_COMPLETED,
            AnalysisProgressEvent::PHASE_STARTED,
            AnalysisProgressEvent::PHASE_COMPLETED,
            AnalysisProgressEvent::PHASE_STARTED,
            AnalysisProgressEvent::PHASE_COMPLETED,
            AnalysisProgressEvent::PHASE_STARTED,
            AnalysisProgressEvent::PHASE_COMPLETED,
            AnalysisProgressEvent::ANALYSIS_COMPLETED,
        ], $reporter->eventTypes());
    }

    public function testDefaultNoOpReporterPreservesTheReport(): void
    {
        $defaultReport = (new DefaultUpgradeAnalyzer(
            scenarioRunner: $this->successfulScenarioRunner()
        ))->analyzeUpgrade($this->request());
        $explicitNoOpReport = (new DefaultUpgradeAnalyzer(
            scenarioRunner: $this->successfulScenarioRunner(),
            progressReporter: new NoOpAnalysisProgressReporter()
        ))->analyzeUpgrade($this->request());

        $defaultReportData = $defaultReport->toArray();
        $explicitNoOpReportData = $explicitNoOpReport->toArray();
        foreach (array_keys($defaultReportData['resolution']['scenarios']) as $index) {
            $defaultReportData['resolution']['scenarios'][$index]['duration_ms'] = 0;
            $explicitNoOpReportData['resolution']['scenarios'][$index]['duration_ms'] = 0;
        }

        self::assertSame($defaultReportData, $explicitNoOpReportData);
    }

    public function testInputFailureCompletesWithAnUnknownDomainResult(): void
    {
        $reporter = new RecordingAnalysisProgressReporter();
        $invalidProject = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'invalid-project-' . bin2hex(random_bytes(8));
        mkdir($invalidProject, 0700, true);
        file_put_contents($invalidProject . DIRECTORY_SEPARATOR . 'composer.json', '{invalid');
        file_put_contents($invalidProject . DIRECTORY_SEPARATOR . 'composer.lock', '{"packages":[]}');

        try {
            $report = (new DefaultUpgradeAnalyzer(progressReporter: $reporter))->analyzeUpgrade(
                new UpgradeRequest($invalidProject, [new UpgradeTarget('fixture/dependency', '^2.0')])
            );

            self::assertSame('unknown', $report->resolutionStatus());
            self::assertSame([
                ['analysis-started', null, null, null, null],
                ['phase-started', AnalysisPhase::PROJECT_LOADING, null, null, null],
                ['phase-completed', AnalysisPhase::PROJECT_LOADING, null, 'failed', null],
                ['phase-started', AnalysisPhase::REPORT_ASSEMBLY, null, null, null],
                ['phase-completed', AnalysisPhase::REPORT_ASSEMBLY, null, 'succeeded', null],
                ['analysis-completed', null, null, 'succeeded', 'unknown'],
            ], $reporter->events());
        } finally {
            (new Filesystem())->remove($invalidProject);
        }
    }

    public function testUnexpectedFailureTerminatesTheProgressSequence(): void
    {
        $reporter = new RecordingAnalysisProgressReporter();
        $request = $this->request();
        $request = new UpgradeRequest(
            $request->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            ['missing-framework']
        );

        try {
            (new DefaultUpgradeAnalyzer(progressReporter: $reporter))->analyzeUpgrade($request);
            self::fail('The unavailable framework must fail analysis.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('missing-framework', $failure->getMessage());
        }

        self::assertSame([
            ['analysis-started', null, null, null, null],
            ['phase-started', AnalysisPhase::PROJECT_LOADING, null, null, null],
            ['phase-completed', AnalysisPhase::PROJECT_LOADING, null, 'succeeded', null],
            ['analysis-failed', null, null, 'failed', null],
        ], $reporter->events());
    }

    public function testReporterFailuresCannotMaskTheOriginalAnalysisFailure(): void
    {
        $reporter = new ThrowingAnalysisProgressReporter();
        $request = $this->request();
        $request = new UpgradeRequest(
            $request->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            ['missing-framework']
        );

        try {
            (new DefaultUpgradeAnalyzer(progressReporter: $reporter))->analyzeUpgrade($request);
            self::fail('The unavailable framework must fail analysis.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('missing-framework', $failure->getMessage());
            self::assertStringNotContainsString('progress reporter failed', $failure->getMessage());
        }

        self::assertSame([
            AnalysisProgressEvent::ANALYSIS_STARTED,
            AnalysisProgressEvent::PHASE_STARTED,
            AnalysisProgressEvent::PHASE_COMPLETED,
            AnalysisProgressEvent::ANALYSIS_FAILED,
        ], $reporter->eventTypes());
    }

    private function successfulScenarioRunner(): ComposerScenarioRunner
    {
        return new ComposerScenarioRunner(null, null, static function (array $command, string $directory): array {
            if ($command[1] === 'validate') {
                return ['exit_code' => 0, 'stdout' => 'Valid.', 'stderr' => ''];
            }

            file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', json_encode([
                'packages' => [['name' => 'fixture/dependency', 'version' => '2.0.0']],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR));

            return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
        });
    }

    private function request(): UpgradeRequest
    {
        $projectPath = dirname(__DIR__, 5)
            . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'project-isolation';

        return new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);
    }
}

final class RecordingAnalysisProgressReporter implements AnalysisProgressReporter
{
    /** @var list<array{string, ?string, ?string, ?string, ?string}> */
    private array $events = [];

    public function report(AnalysisProgressEvent $event): void
    {
        $this->events[] = [
            $event->type(),
            $event->phase(),
            $event->scenario(),
            $event->status(),
            $event->outcome(),
        ];
    }

    /** @return list<array{string, ?string, ?string, ?string, ?string}> */
    public function events(): array
    {
        return $this->events;
    }
}

final class ThrowingAnalysisProgressReporter implements AnalysisProgressReporter
{
    /** @var list<string> */
    private array $eventTypes = [];

    public function report(AnalysisProgressEvent $event): void
    {
        $this->eventTypes[] = $event->type();

        throw new \RuntimeException('progress reporter failed');
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return $this->eventTypes;
    }
}
