<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\SourceImpactFinding;
use PhpUpgradePreflight\Core\Model\StageAnalysis;
use PhpUpgradePreflight\Core\Model\StageBlockerEntry;
use PhpUpgradePreflight\Core\Model\StageTestGuidance;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Source\SymbolOwnershipIndex;

final class StageAssessmentBuilder
{
    private SourceImpactBuilder $sourceImpactBuilder;
    private RiskAndEffortEstimator $estimator;

    public function __construct(
        ?SourceImpactBuilder $sourceImpactBuilder = null,
        ?RiskAndEffortEstimator $estimator = null
    ) {
        $this->sourceImpactBuilder = $sourceImpactBuilder ?? new SourceImpactBuilder();
        $this->estimator = $estimator ?? new RiskAndEffortEstimator();
    }

    /**
     * @param list<\PhpUpgradePreflight\Core\Model\SourceUsage> $inventory
     * @param list<CompatibilityFinding> $findings
     */
    public function build(
        StagedResolution $resolution,
        array $inventory,
        array $findings,
        SymbolOwnershipIndex $ownership,
        EvidenceLedger $evidence,
        ProjectState $project,
        UpgradeRequest $request
    ): StagedResolution {
        $assessedStages = [];
        /** @var array<string, SourceImpactFinding> $impactRegistry */
        $impactRegistry = [];

        foreach ($resolution->stages() as $stage) {
            $stageId = $stage->target()->id();
            $stageFramework = $stage->target()->framework();
            $hop = ['from_major' => $stage->target()->fromMajor(), 'to_major' => $stage->target()->toMajor()];
            $stageFindings = array_values(array_filter(
                $findings,
                static fn (CompatibilityFinding $finding): bool => strtolower($finding->framework()) === $stageFramework
                    && in_array($hop, $finding->appliesToHops(), true)
            ));
            $stageImpact = array_map(
                static fn (SourceImpactFinding $finding): SourceImpactFinding => $finding->withStageIds([$stageId]),
                $this->sourceImpactBuilder->build(
                    $inventory,
                    $stageFindings,
                    $stage->packageChanges(),
                    $ownership,
                    $evidence
                )
            );
            foreach ($stageImpact as $impact) {
                $impactRegistry[$impact->id()] = isset($impactRegistry[$impact->id()])
                    ? $impactRegistry[$impact->id()]->merge($impact)
                    : $impact;
            }

            $blockers = array_values(array_filter(
                $resolution->blockerRegistry(),
                static fn (StageBlockerEntry $blocker): bool => $blocker->stageId() === $stageId
            ));
            $assessedStages[] = $stage->withReportingAssessment(
                $stageFindings,
                $stageImpact,
                $blockers,
                $this->estimator->estimateStageRisk($stage, $blockers, $stageFindings, $stageImpact),
                $this->estimator->estimateStageEffort($stage, $blockers, $stageFindings, $stageImpact),
                $this->actions($stage, $blockers, $stageFindings, $stageImpact),
                $this->tests($stage, $project, $request, $stageFindings, $stageImpact)
            );
        }

        ksort($impactRegistry, SORT_STRING);

        return $resolution->withReportingAssessments($assessedStages, array_values($impactRegistry));
    }

    /**
     * @param list<StageBlockerEntry> $blockers
     * @param list<CompatibilityFinding> $findings
     * @param list<SourceImpactFinding> $impact
     * @return list<string>
     */
    private function actions(StageAnalysis $stage, array $blockers, array $findings, array $impact): array
    {
        $id = $stage->target()->id();
        if ($stage->executionState() === StageAnalysis::SKIPPED) {
            return [sprintf('[%s] Do not advance: this stage was skipped and has no Composer feasibility evidence.', $id)];
        }
        if ($stage->resolutionStatus() === StagedResolution::BLOCKED) {
            $actions = [sprintf('[%s] Resolve every active blocker and rerun this complete stage; do not advance.', $id)];
            foreach ($blockers as $blocker) {
                if (!$blocker->isActive()) {
                    continue;
                }
                foreach ($blocker->options() as $option) {
                    $actions[] = sprintf('[%s] %s', $id, $option);
                }
            }

            return array_values(array_unique($actions));
        }
        if ($stage->resolutionStatus() === StagedResolution::UNKNOWN) {
            return [sprintf('[%s] Resolve the operational uncertainty and rerun this stage without inferring feasibility.', $id)];
        }

        $actions = [sprintf('[%s] Reproduce and review only the selected Composer candidate state before advancing.', $id)];
        foreach ($findings as $finding) {
            $actions[] = sprintf('[%s] Review the original-source finding: %s', $id, $finding->summary());
        }
        if ($impact !== []) {
            $actions[] = sprintf(
                '[%s] Review %d unique actionable source finding(s) against the original project snapshot.',
                $id,
                count($impact)
            );
        }

        return array_values(array_unique($actions));
    }

    /**
     * @param list<CompatibilityFinding> $findings
     * @param list<SourceImpactFinding> $impact
     * @return list<StageTestGuidance>
     */
    private function tests(
        StageAnalysis $stage,
        ProjectState $project,
        UpgradeRequest $request,
        array $findings,
        array $impact
    ): array {
        if ($stage->executionState() === StageAnalysis::SKIPPED) {
            return [new StageTestGuidance(
                $stage->target()->id(),
                'composer-stage-rerun',
                sprintf('Resolve the preceding stop condition, then execute stage %s before selecting migration tests.', $stage->target()->id()),
                null,
                'required'
            )];
        }
        if (!in_array($stage->resolutionStatus(), [StagedResolution::FEASIBLE, StagedResolution::FEASIBLE_WITH_CHANGES], true)) {
            return [new StageTestGuidance(
                $stage->target()->id(),
                'composer-stage-rerun',
                sprintf('Resolve this stage stop condition, then rerun the complete Composer stage %s.', $stage->target()->id()),
                null,
                'required'
            )];
        }

        $id = $stage->target()->id();
        $applicable = TestGuidanceCatalog::applicable(
            TestGuidanceCatalog::hasComposerScript($project, 'test'),
            $findings !== [] || $impact !== []
        );

        $tests = [];
        foreach ($applicable as $spec) {
            $tests[] = new StageTestGuidance(
                $id,
                $spec['id'],
                $this->testPurpose($spec['id'], $stage),
                $spec['command'],
                $spec['grade']
            );
        }

        return $tests;
    }

    private function testPurpose(string $id, StageAnalysis $stage): string
    {
        $stageId = $stage->target()->id();

        return match ($id) {
            TestGuidanceCatalog::COMPOSER_VALIDATION => sprintf('Validate the stage %s manifest.', $stageId),
            TestGuidanceCatalog::PROJECT_TEST_SUITE => sprintf(
                'Run the project test suite for stage %s after applying its evidenced changes.',
                $stageId
            ),
            TestGuidanceCatalog::PLATFORM_REQUIREMENTS => sprintf(
                'Validate stage %s against analysis PHP %s and its recorded platform.',
                $stageId,
                $stage->target()->analysisPhp()
            ),
            default => sprintf('Exercise the original-snapshot findings correlated with stage %s.', $stageId),
        };
    }
}
