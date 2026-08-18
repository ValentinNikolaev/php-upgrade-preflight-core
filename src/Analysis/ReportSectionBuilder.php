<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use Composer\Semver\Semver;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\PlanStage;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\ReportSections;
use PhpUpgradePreflight\Core\Model\RootConstraintChange;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceImpactFinding;
use PhpUpgradePreflight\Core\Model\StageAnalysis;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\TestGuidance;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

final class ReportSectionBuilder
{
    private const DEPENDENCY_BLOCKED = 'blocked';
    private const DEPENDENCY_BLOCKED_WITH_DEGRADED_ANALYSIS = 'blocked_with_degraded_analysis';
    private const DEPENDENCY_ADVISORY_ON_CHANGED_STATE = 'advisory_on_changed_state';
    private const DEPENDENCY_ADVISORY_ON_UNCHANGED_STATE = 'advisory_on_unchanged_state';
    private const DEPENDENCY_ADVISORY_WITH_DEGRADED_ANALYSIS = 'advisory_with_degraded_analysis';
    private const DEPENDENCY_ADVISORY_WITHOUT_EVIDENCE = 'advisory_without_evidence';
    private const DEPENDENCY_TRANSITION_AVAILABLE = 'transition_available';
    private const DEPENDENCY_NO_CHANGE_VERIFIED = 'no_change_verified';
    private const DEPENDENCY_ANALYSIS_DEGRADED = 'analysis_degraded';
    private const DEPENDENCY_WITHOUT_EVIDENCE = 'without_evidence';

    /**
     * @param list<ScenarioResult> $scenarioResults
     * @param list<Blocker> $blockers
     * @param list<SourceImpactFinding> $sourceImpact
     * @param list<CompatibilityFinding> $frameworkFindings
     * @param list<string> $sourceUncertainties
     */
    public function build(
        UpgradeRequest $request,
        ProjectState $project,
        array $scenarioResults,
        LockDiff $lockDiff,
        array $blockers,
        array $sourceImpact,
        array $frameworkFindings,
        array $sourceUncertainties,
        EvidenceLedger $evidence,
        ?StagedResolution $stagedResolution = null
    ): ReportSections {
        $rootConstraintChanges = $this->rootConstraintChanges($request, $project, $evidence);
        $planStages = $this->planStages(
            $request,
            $project,
            $scenarioResults,
            $lockDiff,
            $rootConstraintChanges,
            $blockers,
            $sourceImpact,
            $frameworkFindings,
            $evidence,
            $stagedResolution
        );

        return new ReportSections(
            $rootConstraintChanges,
            $planStages,
            $this->testGuidance($request, $project, $sourceImpact, $frameworkFindings),
            array_values($this->uncertainties($request, $project, $scenarioResults, $sourceUncertainties))
        );
    }

    /** @return list<RootConstraintChange> */
    private function rootConstraintChanges(
        UpgradeRequest $request,
        ProjectState $project,
        EvidenceLedger $evidence
    ): array {
        $requirements = $project->composerJson()->rootRequirements();
        $changes = [];

        foreach ($request->targets()->packageTargets() as $target) {
            $package = $target->package();
            $fromConstraint = $requirements[$package] ?? null;
            $toConstraint = $target->constraint();

            if ($fromConstraint === $toConstraint) {
                continue;
            }

            $changeType = $fromConstraint === null ? 'added' : 'updated';
            $reason = $fromConstraint === null
                ? 'The requested target is not declared as a root requirement.'
                : 'The declared root constraint differs from the requested target.';
            $evidenceId = $evidence->add(
                'root-constraint',
                Evidence::E2_PACKAGE_METADATA,
                sprintf('Compared the root requirement for %s with the requested target.', $package),
                'high',
                [
                    'package' => $package,
                    'from_constraint' => $fromConstraint,
                    'to_constraint' => $toConstraint,
                ]
            )->id();

            $changes[] = new RootConstraintChange(
                $package,
                $changeType,
                $fromConstraint,
                $toConstraint,
                $reason,
                [$evidenceId]
            );
        }

        return $changes;
    }

    /**
     * @param list<RootConstraintChange> $rootConstraintChanges
     * @param list<ScenarioResult> $scenarioResults
     * @param list<Blocker> $blockers
     * @param list<SourceImpactFinding> $sourceImpact
     * @param list<CompatibilityFinding> $frameworkFindings
     * @return list<PlanStage>
     */
    private function planStages(
        UpgradeRequest $request,
        ProjectState $project,
        array $scenarioResults,
        LockDiff $lockDiff,
        array $rootConstraintChanges,
        array $blockers,
        array $sourceImpact,
        array $frameworkFindings,
        EvidenceLedger $evidence,
        ?StagedResolution $stagedResolution = null
    ): array {
        if ($stagedResolution !== null && $stagedResolution->stages() !== []) {
            return $this->executedStagePlan($stagedResolution, $evidence);
        }
        if ($stagedResolution !== null
            && $stagedResolution->executionState() === StagedResolution::SKIPPED
            && $stagedResolution->stopReason() !== 'stage_target_provider_unavailable') {
            return $this->skippedStagePlan($stagedResolution, $evidence);
        }

        $guidanceEvidence = $evidence->add(
            'plan',
            Evidence::E5_HEURISTIC,
            'Generated conservative staged actions from the requested targets and detected findings.',
            'low',
            [
                'target_count' => count($request->targets()),
                'root_constraint_change_count' => count($rootConstraintChanges),
                'blocker_count' => count($blockers),
                'source_finding_count' => count($sourceImpact),
                'framework_finding_count' => count($frameworkFindings),
            ]
        )->id();

        $stages = [
            $this->constraintStage($request, $project, $rootConstraintChanges, $guidanceEvidence),
            $this->dependencyStage(
                $this->dependencyPosture($scenarioResults, $lockDiff, $blockers),
                $blockers,
                $guidanceEvidence
            ),
        ];

        $applicationStage = $this->applicationStage($sourceImpact, $frameworkFindings, $guidanceEvidence);
        if ($applicationStage !== null) {
            $stages[] = $applicationStage;
        }

        $stages[] = $this->validationStage($guidanceEvidence);

        return $stages;
    }

    /** @param list<RootConstraintChange> $rootConstraintChanges */
    private function constraintStage(
        UpgradeRequest $request,
        ProjectState $project,
        array $rootConstraintChanges,
        string $guidanceEvidence
    ): PlanStage {
        $actions = [];
        $references = [$guidanceEvidence];
        foreach ($rootConstraintChanges as $change) {
            $actions[] = sprintf(
                '%s the `%s` root constraint%s `%s`.',
                $change->changeType() === 'added' ? 'Add' : 'Update',
                $change->package(),
                $change->fromConstraint() === null ? ' at' : ' to',
                $change->toConstraint() ?? '-'
            );
            $references = array_merge($references, $change->evidence());
        }
        if ($this->phpConstraintNeedsReview($request, $project)) {
            $actions[] = sprintf(
                'Select a root PHP constraint that includes target platform PHP %s without pinning an exact patch version.',
                $request->targetPhp()
            );
        }
        if ($actions === []) {
            $actions[] = 'Confirm the requested targets still match the root requirements before regenerating the lock file.';
        }

        return new PlanStage(
            'constraints',
            'Prepare the requested root constraint changes before dependency resolution.',
            $actions,
            $references
        );
    }

    /**
     * Decides the dependency-stage posture from resolution evidence alone.
     *
     * Kept separate from {@see dependencyStage()} so that rewording never requires
     * re-reasoning about Composer resolution semantics, and vice versa.
     *
     * @param list<ScenarioResult> $scenarioResults
     * @param list<Blocker> $blockers
     */
    private function dependencyPosture(array $scenarioResults, LockDiff $lockDiff, array $blockers): string
    {
        $hasSuccessfulScenario = $this->hasSuccessfulScenario($scenarioResults);
        $hasOperationalFailure = $this->hasOperationalFailure($scenarioResults);
        $hasPackageChanges = $lockDiff->packageChanges() !== [];

        if ($this->hasResolutionBlocker($blockers)) {
            return $hasOperationalFailure
                ? self::DEPENDENCY_BLOCKED_WITH_DEGRADED_ANALYSIS
                : self::DEPENDENCY_BLOCKED;
        }
        if ($blockers !== [] && $hasSuccessfulScenario) {
            return $hasPackageChanges
                ? self::DEPENDENCY_ADVISORY_ON_CHANGED_STATE
                : self::DEPENDENCY_ADVISORY_ON_UNCHANGED_STATE;
        }
        if ($blockers !== []) {
            return $hasOperationalFailure
                ? self::DEPENDENCY_ADVISORY_WITH_DEGRADED_ANALYSIS
                : self::DEPENDENCY_ADVISORY_WITHOUT_EVIDENCE;
        }
        if ($hasSuccessfulScenario) {
            return $hasPackageChanges
                ? self::DEPENDENCY_TRANSITION_AVAILABLE
                : self::DEPENDENCY_NO_CHANGE_VERIFIED;
        }

        return $hasOperationalFailure
            ? self::DEPENDENCY_ANALYSIS_DEGRADED
            : self::DEPENDENCY_WITHOUT_EVIDENCE;
    }

    /** @param list<Blocker> $blockers */
    private function dependencyStage(string $posture, array $blockers, string $guidanceEvidence): PlanStage
    {
        $actions = [];
        $references = [$guidanceEvidence];
        foreach ($blockers as $blocker) {
            $actions[] = $blocker->blocksResolution()
                ? sprintf('Resolve the `%s` blocker affecting `%s`.', $blocker->type(), $blocker->subject())
                : sprintf('Address the `%s` advisory affecting `%s`.', $blocker->type(), $blocker->subject());
            $references = array_merge($references, $blocker->evidence());
        }

        $guidance = $this->dependencyGuidance($posture);

        return new PlanStage(
            'dependencies',
            $guidance['summary'],
            array_merge($actions, $guidance['actions']),
            $references
        );
    }

    /**
     * The wording for each dependency posture, and nothing else.
     *
     * @return array{summary: string, actions: list<string>}
     */
    private function dependencyGuidance(string $posture): array
    {
        return match ($posture) {
            self::DEPENDENCY_BLOCKED => [
                'summary' => 'Resolve dependency blockers and review the resulting lockfile transition.',
                'actions' => ['Rerun the isolated Composer scenarios after resolving the reported blockers.'],
            ],
            self::DEPENDENCY_BLOCKED_WITH_DEGRADED_ANALYSIS => [
                'summary' => 'Resolve dependency blockers and review the resulting lockfile transition.',
                'actions' => [
                    'Restore the Composer analysis environment so every scenario can complete.',
                    'Rerun the isolated Composer scenarios after resolving the reported blockers.',
                ],
            ],
            self::DEPENDENCY_ADVISORY_ON_CHANGED_STATE => [
                'summary' => 'Address dependency maintenance advisories in the feasible dependency state.',
                'actions' => [
                    'Apply and review the smallest successful dependency transition before addressing maintenance advisories.',
                ],
            ],
            self::DEPENDENCY_ADVISORY_ON_UNCHANGED_STATE => [
                'summary' => 'Address dependency maintenance advisories in the feasible dependency state.',
                'actions' => ['Use the verified dependency state as the baseline for addressing maintenance advisories.'],
            ],
            self::DEPENDENCY_ADVISORY_WITH_DEGRADED_ANALYSIS => [
                'summary' => 'Address dependency maintenance advisories and re-establish analysis confidence.',
                'actions' => [
                    'Restore the Composer analysis environment and rerun the isolated scenarios before changing the lockfile.',
                ],
            ],
            self::DEPENDENCY_ADVISORY_WITHOUT_EVIDENCE => [
                'summary' => 'Address dependency maintenance advisories and establish dependency-resolution evidence.',
                'actions' => ['Run the isolated Composer scenarios before changing the lockfile.'],
            ],
            self::DEPENDENCY_TRANSITION_AVAILABLE => [
                'summary' => 'Apply and review the successful dependency transition.',
                'actions' => ['Regenerate `composer.lock` with the smallest successful dependency transition.'],
            ],
            self::DEPENDENCY_NO_CHANGE_VERIFIED => [
                'summary' => 'Preserve the verified no-change dependency state.',
                'actions' => ['Keep the existing lockfile after Composer confirms that no package changes are required.'],
            ],
            self::DEPENDENCY_ANALYSIS_DEGRADED => [
                'summary' => 'Re-establish dependency-analysis confidence before making changes.',
                'actions' => [
                    'Restore the Composer analysis environment and rerun the isolated scenarios before changing the lockfile.',
                ],
            ],
            default => [
                'summary' => 'Establish dependency-resolution evidence before making changes.',
                'actions' => ['Run the isolated Composer scenarios before changing the lockfile.'],
            ],
        };
    }

    /**
     * @param list<SourceImpactFinding> $sourceImpact
     * @param list<CompatibilityFinding> $frameworkFindings
     */
    private function applicationStage(
        array $sourceImpact,
        array $frameworkFindings,
        string $guidanceEvidence
    ): ?PlanStage {
        if ($sourceImpact === [] && $frameworkFindings === []) {
            return null;
        }

        $actions = [];
        $references = [$guidanceEvidence];
        if ($sourceImpact !== []) {
            $actions[] = 'Review the reported source locations and adapt affected application code.';
            $references = array_merge($references, $this->sourceEvidence($sourceImpact));
        }
        if ($frameworkFindings !== []) {
            $actions[] = 'Address framework compatibility findings before runtime validation.';
            $references = array_merge($references, $this->frameworkEvidence($frameworkFindings));
        }

        return new PlanStage(
            'application',
            'Apply source and framework migration work after dependency resolution is stable.',
            $actions,
            $references
        );
    }

    private function validationStage(string $guidanceEvidence): PlanStage
    {
        return new PlanStage(
            'validation',
            'Validate the upgraded project on the target runtime before release.',
            [
                'Validate the Composer manifest and installed platform requirements.',
                'Run the project test suite and focused regression tests.',
            ],
            [$guidanceEvidence]
        );
    }

    /** @return list<PlanStage> */
    private function executedStagePlan(StagedResolution $resolution, EvidenceLedger $evidence): array
    {
        $plan = [];
        foreach ($resolution->stages() as $stage) {
            $stageId = $stage->target()->id();
            $status = $stage->resolutionStatus();
            $isProved = $stage->executionState() === StageAnalysis::EXECUTED
                && in_array($status, [StagedResolution::FEASIBLE, StagedResolution::FEASIBLE_WITH_CHANGES], true)
                && $stage->outputState() !== null;
            $evidenceId = $evidence->add(
                'stage-plan',
                Evidence::E5_HEURISTIC,
                sprintf('Generated recommendations from the executed outcome of stage %s.', $stageId),
                $isProved ? 'medium' : 'low',
                [
                    'stage_id' => $stageId,
                    'execution_state' => $stage->executionState(),
                    'resolution_status' => $status,
                    'transition_recommended' => $isProved,
                ]
            )->id();

            $actions = $stage->recommendedActions();
            if ($actions === []) {
                $actions[] = sprintf('[%s] No transition is recommended without an executed selectable candidate.', $stageId);
            }
            if ($isProved) {
                foreach ($stage->tests() as $test) {
                    $testData = $test->toArray();
                    $actions[] = sprintf('[%s] %s: %s', $stageId, $testData['name'], $testData['purpose']);
                }
            }
            $summary = $isProved
                ? sprintf('Apply only the selected %s candidate, then validate before advancing.', $stageId)
                : sprintf('Stop at %s; its transition is not proved and must be rerun.', $stageId);
            $plan[] = new PlanStage(
                $stageId,
                $summary,
                array_values(array_unique($actions)),
                array_values(array_unique(array_merge([$evidenceId], $stage->evidenceReferences()))),
                $stageId
            );

            if (!$isProved) {
                break;
            }
        }

        return $plan;
    }

    /** @return list<PlanStage> */
    private function skippedStagePlan(StagedResolution $resolution, EvidenceLedger $evidence): array
    {
        $reason = $resolution->stopReason() ?? 'staged_resolution_unavailable';
        $evidenceId = $evidence->add(
            'stage-plan',
            Evidence::E5_HEURISTIC,
            'Stopped the recommended plan because staged Composer resolution did not produce a stage.',
            'low',
            [
                'execution_state' => $resolution->executionState(),
                'resolution_status' => $resolution->status(),
                'stop_reason' => $reason,
                'transition_recommended' => false,
            ]
        )->id();

        return [new PlanStage(
            'staged-resolution',
            sprintf('Stop before the missing staged transition; staged Composer resolution ended with %s.', $reason),
            [sprintf(
                'Resolve the staged analysis stop condition `%s` and rerun analysis before applying a framework transition.',
                $reason
            )],
            array_values(array_unique(array_merge([$evidenceId], $resolution->evidenceReferences())))
        )];
    }

    /**
     * @param list<SourceImpactFinding> $sourceImpact
     * @param list<CompatibilityFinding> $frameworkFindings
     * @return list<TestGuidance>
     */
    private function testGuidance(
        UpgradeRequest $request,
        ProjectState $project,
        array $sourceImpact,
        array $frameworkFindings
    ): array {
        $hasTestScript = TestGuidanceCatalog::hasComposerScript($project, 'test');
        $applicable = TestGuidanceCatalog::applicable(
            $hasTestScript,
            $sourceImpact !== [] || $frameworkFindings !== []
        );

        $tests = [];
        foreach ($applicable as $spec) {
            $tests[] = new TestGuidance(
                $spec['id'],
                $this->testPurpose($spec['id'], $request, $hasTestScript),
                $spec['command'],
                $spec['grade']
            );
        }

        return $tests;
    }

    private function testPurpose(string $id, UpgradeRequest $request, bool $hasTestScript): string
    {
        return match ($id) {
            TestGuidanceCatalog::COMPOSER_VALIDATION => 'Validate the edited Composer manifest before dependency installation.',
            TestGuidanceCatalog::PROJECT_TEST_SUITE => $hasTestScript
                ? 'Run the project test suite after applying the dependency and source changes.'
                : 'Identify and run the project test suite; no Composer test script was detected.',
            TestGuidanceCatalog::PLATFORM_REQUIREMENTS => $request->targetPhp() === null
                ? 'Confirm the installed dependencies satisfy the deployment platform.'
                : sprintf(
                    'Confirm the installed dependencies satisfy PHP %s and the deployment extensions.',
                    $request->targetPhp()
                ),
            default => 'Add or run focused regression coverage for the reported source and framework findings.',
        };
    }

    /** @param list<ScenarioResult> $scenarioResults @param list<string> $sourceUncertainties @return list<string> */
    private function uncertainties(
        UpgradeRequest $request,
        ProjectState $project,
        array $scenarioResults,
        array $sourceUncertainties
    ): array {
        $uncertainties = $sourceUncertainties;
        foreach ($scenarioResults as $result) {
            if ($result->scenario()->isBaselineValidation() && $result->isValidationFailure()) {
                $uncertainties[] = 'Composer baseline validation did not pass; target results may include pre-existing manifest or lockfile issues.';
                continue;
            }

            if ($result->isOperationalFailure()) {
                $uncertainties[] = sprintf(
                    'Composer scenario "%s" could not complete because of an analysis-environment failure.',
                    $result->scenario()->name()
                );
            }
        }

        $uncertainties[] = 'Dependency resolution does not prove application runtime compatibility; the project test suite must run on the target runtime.';

        $phpConstraintUncertainty = $this->phpConstraintUncertainty($request, $project);
        if ($phpConstraintUncertainty !== null) {
            $uncertainties[] = $phpConstraintUncertainty;
        }

        if (!TestGuidanceCatalog::hasComposerScript($project, 'test')) {
            $uncertainties[] = 'No Composer "test" script was found, so the project\'s canonical test command is unknown.';
        }

        return array_values(array_unique($uncertainties));
    }

    /** @param list<ScenarioResult> $scenarioResults */
    private function hasSuccessfulScenario(array $scenarioResults): bool
    {
        foreach ($scenarioResults as $result) {
            if ($result->scenario()->determinesTargetFeasibility() && $result->succeeded()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Blocker> $blockers */
    private function hasResolutionBlocker(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if ($blocker->blocksResolution()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<ScenarioResult> $scenarioResults */
    private function hasOperationalFailure(array $scenarioResults): bool
    {
        foreach ($scenarioResults as $result) {
            if (!$result->scenario()->isPartialTargetProbe() && $result->isOperationalFailure()) {
                return true;
            }
        }

        return false;
    }

    private function phpConstraintNeedsReview(UpgradeRequest $request, ProjectState $project): bool
    {
        return $this->phpConstraintUncertainty($request, $project) !== null;
    }

    private function phpConstraintUncertainty(UpgradeRequest $request, ProjectState $project): ?string
    {
        $targetPhp = $request->targetPhp();
        if ($targetPhp === null) {
            return null;
        }

        $constraint = $project->composerJson()->rootRequirements()['php'] ?? null;
        if ($constraint === null) {
            return sprintf(
                'No root PHP constraint is declared; select a Composer PHP constraint that includes target platform PHP %s.',
                $targetPhp
            );
        }

        try {
            if (Semver::satisfies($targetPhp, $constraint)) {
                return null;
            }
        } catch (\UnexpectedValueException $exception) {
            return sprintf(
                'Root PHP constraint "%s" could not be evaluated against target platform PHP %s.',
                $constraint,
                $targetPhp
            );
        }

        return sprintf(
            'Root PHP constraint "%s" does not include target platform PHP %s; select an appropriate Composer constraint instead of using the exact simulated platform version.',
            $constraint,
            $targetPhp
        );
    }

    /** @param list<SourceImpactFinding> $sourceImpact @return list<string> */
    private function sourceEvidence(array $sourceImpact): array
    {
        $references = [];
        foreach ($sourceImpact as $usage) {
            $references = array_merge($references, $usage->evidence());
        }

        return array_values($references);
    }

    /** @param list<CompatibilityFinding> $frameworkFindings @return list<string> */
    private function frameworkEvidence(array $frameworkFindings): array
    {
        $references = [];
        foreach ($frameworkFindings as $finding) {
            $references = array_merge($references, $finding->evidence());
        }

        return array_values($references);
    }
}
