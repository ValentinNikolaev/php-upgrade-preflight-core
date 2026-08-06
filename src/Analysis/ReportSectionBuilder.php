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
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\TestGuidance;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

final class ReportSectionBuilder
{
    /**
     * @param list<ScenarioResult> $scenarioResults
     * @param list<Blocker> $blockers
     * @param list<SourceUsage> $sourceImpact
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
        EvidenceLedger $evidence
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
            $evidence
        );

        return new ReportSections(
            $rootConstraintChanges,
            $planStages,
            $this->testGuidance($request, $project, $sourceImpact, $frameworkFindings),
            $this->uncertainties($request, $project, $scenarioResults, $sourceUncertainties)
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
     * @param list<SourceUsage> $sourceImpact
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
        EvidenceLedger $evidence
    ): array {
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

        $constraintActions = [];
        $constraintEvidence = [$guidanceEvidence];
        foreach ($rootConstraintChanges as $change) {
            $constraintActions[] = sprintf(
                '%s the `%s` root constraint%s `%s`.',
                $change->changeType() === 'added' ? 'Add' : 'Update',
                $change->package(),
                $change->fromConstraint() === null ? ' at' : ' to',
                $change->toConstraint() ?? '-'
            );
            $constraintEvidence = array_merge($constraintEvidence, $change->evidence());
        }
        if ($this->phpConstraintNeedsReview($request, $project)) {
            $constraintActions[] = sprintf(
                'Select a root PHP constraint that includes target platform PHP %s without pinning an exact patch version.',
                $request->targetPhp()
            );
        }
        if ($constraintActions === []) {
            $constraintActions[] = 'Confirm the requested targets still match the root requirements before regenerating the lock file.';
        }

        $dependencyActions = [];
        $dependencyEvidence = [$guidanceEvidence];
        foreach ($blockers as $blocker) {
            $dependencyActions[] = sprintf('Resolve the `%s` blocker affecting `%s`.', $blocker->type(), $blocker->subject());
            $dependencyEvidence = array_merge($dependencyEvidence, $blocker->evidence());
        }
        $hasSuccessfulScenario = $this->hasSuccessfulScenario($scenarioResults);
        $hasOperationalFailure = $this->hasOperationalFailure($scenarioResults);
        if ($blockers !== []) {
            if ($hasOperationalFailure) {
                $dependencyActions[] = 'Restore the Composer analysis environment so every scenario can complete.';
            }
            $dependencyActions[] = 'Rerun the isolated Composer scenarios after resolving the reported blockers.';
            $dependencySummary = 'Resolve dependency blockers and review the resulting lockfile transition.';
        } elseif ($hasSuccessfulScenario && $lockDiff->packageChanges() !== []) {
            $dependencyActions[] = 'Regenerate `composer.lock` with the smallest successful dependency transition.';
            $dependencySummary = 'Apply and review the successful dependency transition.';
        } elseif ($hasSuccessfulScenario) {
            $dependencyActions[] = 'Keep the existing lockfile after Composer confirms that no package changes are required.';
            $dependencySummary = 'Preserve the verified no-change dependency state.';
        } elseif ($hasOperationalFailure) {
            $dependencyActions[] = 'Restore the Composer analysis environment and rerun the isolated scenarios before changing the lockfile.';
            $dependencySummary = 'Re-establish dependency-analysis confidence before making changes.';
        } else {
            $dependencyActions[] = 'Run the isolated Composer scenarios before changing the lockfile.';
            $dependencySummary = 'Establish dependency-resolution evidence before making changes.';
        }

        $stages = [
            new PlanStage(
                'constraints',
                'Prepare the requested root constraint changes before dependency resolution.',
                $constraintActions,
                $constraintEvidence
            ),
            new PlanStage(
                'dependencies',
                $dependencySummary,
                $dependencyActions,
                $dependencyEvidence
            ),
        ];

        if ($sourceImpact !== [] || $frameworkFindings !== []) {
            $applicationActions = [];
            $applicationEvidence = [$guidanceEvidence];
            if ($sourceImpact !== []) {
                $applicationActions[] = 'Review the reported source locations and adapt affected application code.';
                $applicationEvidence = array_merge($applicationEvidence, $this->sourceEvidence($sourceImpact));
            }
            if ($frameworkFindings !== []) {
                $applicationActions[] = 'Address framework compatibility findings before runtime validation.';
                $applicationEvidence = array_merge($applicationEvidence, $this->frameworkEvidence($frameworkFindings));
            }

            $stages[] = new PlanStage(
                'application',
                'Apply source and framework migration work after dependency resolution is stable.',
                $applicationActions,
                $applicationEvidence
            );
        }

        $stages[] = new PlanStage(
            'validation',
            'Validate the upgraded project on the target runtime before release.',
            [
                'Validate the Composer manifest and installed platform requirements.',
                'Run the project test suite and focused regression tests.',
            ],
            [$guidanceEvidence]
        );

        return $stages;
    }

    /**
     * @param list<SourceUsage> $sourceImpact
     * @param list<CompatibilityFinding> $frameworkFindings
     * @return list<TestGuidance>
     */
    private function testGuidance(
        UpgradeRequest $request,
        ProjectState $project,
        array $sourceImpact,
        array $frameworkFindings
    ): array {
        $hasTestScript = $this->hasComposerScript($project, 'test');
        $tests = [
            new TestGuidance(
                'composer-validation',
                'Validate the edited Composer manifest before dependency installation.',
                'composer validate --strict',
                'required'
            ),
            new TestGuidance(
                'project-test-suite',
                $hasTestScript
                    ? 'Run the project test suite after applying the dependency and source changes.'
                    : 'Identify and run the project test suite; no Composer test script was detected.',
                $hasTestScript ? 'composer test' : null,
                'required'
            ),
            new TestGuidance(
                'platform-requirements',
                $request->targetPhp() === null
                    ? 'Confirm the installed dependencies satisfy the deployment platform.'
                    : sprintf('Confirm the installed dependencies satisfy PHP %s and the deployment extensions.', $request->targetPhp()),
                'composer check-platform-reqs',
                'required'
            ),
        ];

        if ($sourceImpact !== [] || $frameworkFindings !== []) {
            $tests[] = new TestGuidance(
                'focused-regressions',
                'Add or run focused regression coverage for the reported source and framework findings.',
                null,
                'recommended'
            );
        }

        return $tests;
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

        if (!$this->hasComposerScript($project, 'test')) {
            $uncertainties[] = 'No Composer "test" script was found, so the project\'s canonical test command is unknown.';
        }

        return array_values(array_unique($uncertainties));
    }

    /** @param list<ScenarioResult> $scenarioResults */
    private function hasSuccessfulScenario(array $scenarioResults): bool
    {
        foreach ($scenarioResults as $result) {
            if (!$result->scenario()->isBaselineValidation() && $result->succeeded()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<ScenarioResult> $scenarioResults */
    private function hasOperationalFailure(array $scenarioResults): bool
    {
        foreach ($scenarioResults as $result) {
            if ($result->isOperationalFailure()) {
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

    private function hasComposerScript(ProjectState $project, string $name): bool
    {
        $scripts = $project->composerJson()->data()['scripts'] ?? null;

        return is_array($scripts) && array_key_exists($name, $scripts);
    }

    /** @param list<SourceUsage> $sourceImpact @return list<string> */
    private function sourceEvidence(array $sourceImpact): array
    {
        $references = [];
        foreach ($sourceImpact as $usage) {
            $references = array_merge($references, $usage->evidence());
        }

        return $references;
    }

    /** @param list<CompatibilityFinding> $frameworkFindings @return list<string> */
    private function frameworkEvidence(array $frameworkFindings): array
    {
        $references = [];
        foreach ($frameworkFindings as $finding) {
            $references = array_merge($references, $finding->evidence());
        }

        return $references;
    }
}
