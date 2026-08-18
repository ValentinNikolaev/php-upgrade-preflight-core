<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Framework\FrameworkStageTargetProvider;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

/**
 * Decides whether a staged Composer chain may run at all.
 *
 * Selects the single stage-target provider, asks it for a plan, validates that
 * plan against the staged contract, and enforces the hop and process budgets.
 * Every rejection is a skipped {@see StagedResolution} carrying the evidence the
 * provider produced, so no Composer process starts for a plan that cannot run.
 */
final class StagePlanResolver
{
    private StageAttemptPlanner $attemptPlanner;

    public function __construct(?StageAttemptPlanner $attemptPlanner = null)
    {
        $this->attemptPlanner = $attemptPlanner ?? new StageAttemptPlanner();
    }

    /** @param list<FrameworkIntegration> $providers */
    public function resolve(
        array $providers,
        ProjectState $project,
        UpgradeRequest $request,
        EvidenceLedger $evidence
    ): StagePlanResolution {
        if ($providers === []) {
            return StagePlanResolution::skipped(
                StagedResolution::skipped('stage_target_provider_unavailable')
            );
        }
        if (count($providers) > 1) {
            $names = array_map(static fn (FrameworkIntegration $provider): string => $provider->name(), $providers);
            sort($names, SORT_STRING);
            $evidenceId = $evidence->add(
                'stage-provider-conflict',
                Evidence::E2_PACKAGE_METADATA,
                'Staged Composer analysis was skipped because several active adapters supplied stage targets.',
                'high',
                ['providers' => $names]
            )->id();

            return StagePlanResolution::skipped(
                StagedResolution::skipped('multiple_stage_target_providers', null, [$evidenceId])
            );
        }

        /** @var FrameworkIntegration&FrameworkStageTargetProvider $provider */
        $provider = $providers[0];
        $providerName = $provider->name();
        $evidenceBeforePlanning = array_fill_keys(array_map(
            static fn (Evidence $item): string => $item->id(),
            $evidence->all()
        ), true);
        try {
            $plan = $provider->planStages($project, $request, $evidence);
        } catch (\Throwable) {
            $providerEvidence = $this->newEvidenceReferences($evidence, $evidenceBeforePlanning);
            $evidenceId = $evidence->add(
                'stage-plan-invalid',
                Evidence::E2_PACKAGE_METADATA,
                'The active adapter failed while producing its staged target chain.',
                'high',
                ['provider' => $providerName, 'reason' => 'provider_failure']
            )->id();

            return StagePlanResolution::skipped(StagedResolution::skipped(
                'invalid_stage_plan',
                $providerName,
                array_values(array_unique(array_merge($providerEvidence, [$evidenceId])))
            ));
        }
        $stages = $plan->stages();
        $planEvidence = $plan->evidence();
        foreach ($stages as $stage) {
            $planEvidence = array_merge($planEvidence, $stage->evidence());
            foreach ($stage->remediationTargets() as $target) {
                $planEvidence = array_merge($planEvidence, $stage->remediationEvidence($target->package()));
            }
        }
        $planEvidence = array_values(array_unique($planEvidence));
        $providerEvidence = $this->newEvidenceReferences($evidence, $evidenceBeforePlanning);
        $validationFailure = $this->validatePlan($stages, $plan->provider(), $providerName);
        if ($validationFailure === null) {
            foreach ($planEvidence as $reference) {
                if (!$evidence->has($reference)) {
                    $validationFailure = 'missing_evidence_reference';
                    break;
                }
            }
        }
        if ($validationFailure === null && array_diff($providerEvidence, $planEvidence) !== []) {
            $validationFailure = 'unreferenced_provider_evidence';
        }
        if ($validationFailure !== null) {
            $evidenceId = $evidence->add(
                'stage-plan-invalid',
                Evidence::E2_PACKAGE_METADATA,
                'The active adapter returned an invalid staged target chain.',
                'high',
                ['provider' => $plan->provider(), 'reason' => $validationFailure]
            )->id();

            return StagePlanResolution::skipped(StagedResolution::skipped(
                'invalid_stage_plan',
                $plan->provider(),
                array_values(array_unique(array_merge(
                    array_values(array_filter(
                        $planEvidence,
                        static fn (string $reference): bool => $evidence->has($reference)
                    )),
                    $providerEvidence,
                    [$evidenceId]
                )))
            ));
        }
        if (!$plan->isAvailable()) {
            return StagePlanResolution::skipped(StagedResolution::skipped(
                (string) $plan->unavailableReason(),
                $plan->provider(),
                $plan->evidence()
            ));
        }
        if (count($stages) > StagedAnalysisPolicy::MAX_HOPS) {
            $evidenceId = $evidence->add(
                'stage-hop-budget',
                Evidence::E5_HEURISTIC,
                'Staged Composer analysis exceeded the approved hop budget and was not executed.',
                'high',
                ['requested_hops' => count($stages), 'max_hops' => StagedAnalysisPolicy::MAX_HOPS]
            )->id();

            return StagePlanResolution::skipped(StagedResolution::skipped(
                'hop_budget_exceeded',
                $plan->provider(),
                array_values(array_unique(array_merge($planEvidence, [$evidenceId])))
            ));
        }
        $projectedProcesses = $this->attemptPlanner->projectedWorstCaseComposerProcesses($stages);
        if ($projectedProcesses > StagedAnalysisPolicy::MAX_COMPOSER_PROCESSES) {
            $evidenceId = $evidence->add(
                'stage-process-budget',
                Evidence::E5_HEURISTIC,
                'Staged Composer analysis exceeded the approved process-expansion budget and was not executed.',
                'high',
                [
                    'projected_processes' => $projectedProcesses,
                    'max_processes' => StagedAnalysisPolicy::MAX_COMPOSER_PROCESSES,
                ]
            )->id();

            return StagePlanResolution::skipped(StagedResolution::skipped(
                'process_budget_exceeded',
                $plan->provider(),
                array_values(array_unique(array_merge($planEvidence, [$evidenceId])))
            ));
        }

        return StagePlanResolution::executable($plan->provider(), $stages, $plan->evidence());
    }

    /** @param list<FrameworkStageTarget> $stages */
    private function validatePlan(array $stages, string $planProvider, string $activeProvider): ?string
    {
        if ($planProvider !== $activeProvider) {
            return 'provider_identity_mismatch';
        }
        $ids = [];
        $previous = null;
        foreach ($stages as $stage) {
            if ($stage->framework() !== $planProvider) {
                return 'stage_framework_mismatch';
            }
            if (isset($ids[$stage->id()])) {
                return 'duplicate_stage_id';
            }
            $ids[$stage->id()] = true;
            if ($previous !== null
                && ($previous->framework() !== $stage->framework()
                    || $previous->toMajor() !== $stage->fromMajor())) {
                return 'non_contiguous_stage_chain';
            }
            $previous = $stage;
        }

        return null;
    }

    /**
     * @param array<string, true> $existing
     * @return list<string>
     */
    private function newEvidenceReferences(EvidenceLedger $evidence, array $existing): array
    {
        return array_values(array_map(
            static fn (Evidence $item): string => $item->id(),
            array_filter(
                $evidence->all(),
                static fn (Evidence $item): bool => !isset($existing[$item->id()])
            )
        ));
    }
}
