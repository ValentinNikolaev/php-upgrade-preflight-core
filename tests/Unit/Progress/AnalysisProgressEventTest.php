<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Progress;

use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PhpUpgradePreflight\Core\Progress\AnalysisPhase;
use PhpUpgradePreflight\Core\Progress\AnalysisProgressEvent;
use PHPUnit\Framework\TestCase;

final class AnalysisProgressEventTest extends TestCase
{
    public function testPhasesExposeAStableOrderedSet(): void
    {
        $phases = [
            AnalysisPhase::PROJECT_LOADING,
            AnalysisPhase::COMPOSER_FEASIBILITY,
            AnalysisPhase::STAGED_RESOLUTION,
            AnalysisPhase::SOURCE_SCAN,
            AnalysisPhase::FRAMEWORK_EVALUATION,
            AnalysisPhase::REPORT_ASSEMBLY,
        ];

        self::assertSame($phases, AnalysisPhase::all());
        foreach ($phases as $phase) {
            AnalysisPhase::assertKnown($phase);
        }
        self::addToAssertionCount(count($phases));
    }

    public function testUnknownPhaseIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown analysis phase "future-phase".');

        AnalysisProgressEvent::phaseStarted('future-phase');
    }

    public function testUnknownCompletionStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown analysis progress status "pending".');

        AnalysisProgressEvent::phaseCompleted(AnalysisPhase::SOURCE_SCAN, 'pending');
    }

    public function testFactoriesExposeCanonicalEventPayloads(): void
    {
        $scenario = $this->scenario();
        $events = [
            AnalysisProgressEvent::analysisStarted(),
            AnalysisProgressEvent::phaseStarted(AnalysisPhase::SOURCE_SCAN),
            AnalysisProgressEvent::phaseCompleted(AnalysisPhase::SOURCE_SCAN),
            AnalysisProgressEvent::phaseCompleted(
                AnalysisPhase::SOURCE_SCAN,
                AnalysisProgressEvent::STATUS_FAILED
            ),
            AnalysisProgressEvent::scenarioStarted($scenario),
            AnalysisProgressEvent::scenarioCompleted($this->successfulScenarioResult()),
            AnalysisProgressEvent::scenarioCompleted($this->failedScenarioResult()),
            AnalysisProgressEvent::analysisCompleted($this->report()),
            AnalysisProgressEvent::analysisFailed(),
        ];

        self::assertSame([
            [AnalysisProgressEvent::ANALYSIS_STARTED, null, null, null, null],
            [AnalysisProgressEvent::PHASE_STARTED, AnalysisPhase::SOURCE_SCAN, null, null, null],
            [
                AnalysisProgressEvent::PHASE_COMPLETED,
                AnalysisPhase::SOURCE_SCAN,
                null,
                AnalysisProgressEvent::STATUS_SUCCEEDED,
                null,
            ],
            [
                AnalysisProgressEvent::PHASE_COMPLETED,
                AnalysisPhase::SOURCE_SCAN,
                null,
                AnalysisProgressEvent::STATUS_FAILED,
                null,
            ],
            [
                AnalysisProgressEvent::SCENARIO_STARTED,
                AnalysisPhase::COMPOSER_FEASIBILITY,
                'fixture-scenario',
                null,
                null,
            ],
            [
                AnalysisProgressEvent::SCENARIO_COMPLETED,
                AnalysisPhase::COMPOSER_FEASIBILITY,
                'fixture-scenario',
                AnalysisProgressEvent::STATUS_SUCCEEDED,
                ScenarioResult::OUTCOME_SUCCESS,
            ],
            [
                AnalysisProgressEvent::SCENARIO_COMPLETED,
                AnalysisPhase::COMPOSER_FEASIBILITY,
                'fixture-scenario',
                AnalysisProgressEvent::STATUS_FAILED,
                ScenarioResult::OUTCOME_TIMEOUT,
            ],
            [
                AnalysisProgressEvent::ANALYSIS_COMPLETED,
                null,
                null,
                AnalysisProgressEvent::STATUS_SUCCEEDED,
                'unknown',
            ],
            [AnalysisProgressEvent::ANALYSIS_FAILED, null, null, AnalysisProgressEvent::STATUS_FAILED, null],
        ], array_map(static fn (AnalysisProgressEvent $event): array => [
            $event->type(),
            $event->phase(),
            $event->scenario(),
            $event->status(),
            $event->outcome(),
        ], $events));
    }

    private function scenario(): Scenario
    {
        return new Scenario(
            'fixture-scenario',
            new UpgradeTargetSet([new UpgradeTarget('vendor/package', '^2.0')])
        );
    }

    private function successfulScenarioResult(): ScenarioResult
    {
        return new ScenarioResult(
            $this->scenario(),
            0,
            '',
            '',
            new ComposerLock([]),
            null,
            null,
            null,
            [],
            0,
            null,
            [],
            ScenarioResult::OUTCOME_SUCCESS
        );
    }

    private function failedScenarioResult(): ScenarioResult
    {
        return new ScenarioResult(
            $this->scenario(),
            1,
            '',
            '',
            null,
            null,
            ScenarioResult::FAILURE_OPERATIONAL,
            null,
            [],
            0,
            null,
            [],
            ScenarioResult::OUTCOME_TIMEOUT
        );
    }

    private function report(): UpgradeReport
    {
        $request = new UpgradeRequest(
            dirname(__DIR__, 5),
            [new UpgradeTarget('vendor/package', '^2.0')]
        );

        return new UpgradeReport(
            $request,
            new ProjectState($request->projectPath(), new ComposerJson([]), new ComposerLock([])),
            [],
            new LockDiff([]),
            [],
            [],
            [],
            new RiskSummary('low', []),
            new EffortEstimate([0, 0], 'high', [], []),
            [],
            []
        );
    }
}
