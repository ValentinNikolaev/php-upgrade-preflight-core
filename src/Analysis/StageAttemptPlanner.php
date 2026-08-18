<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;

/**
 * Owns the staged attempt vocabulary: which Composer attempts one stage may run,
 * in which order, and how many Composer processes the whole chain can cause.
 *
 * Both the plan-time process budget and the execution loop read the same
 * definitions, so the projected worst case cannot drift from what actually runs.
 */
final class StageAttemptPlanner
{
    /**
     * @return list<array{strategy: string, targets: UpgradeTargetSet, with_all_dependencies: bool}>
     */
    public function definitionsFor(FrameworkStageTarget $stage): array
    {
        $baseTargets = $stage->targets()->packageTargets();
        $definitions = [[
            'strategy' => 'target_only',
            'targets' => new UpgradeTargetSet($baseTargets, $stage->analysisPhp()),
            'with_all_dependencies' => false,
        ]];
        $remediations = $stage->remediationTargets();
        if ($remediations === []) {
            $definitions[] = [
                'strategy' => 'locked_package_remediation',
                'targets' => new UpgradeTargetSet($baseTargets, $stage->analysisPhp()),
                'with_all_dependencies' => true,
            ];
        } else {
            $definitions[] = [
                'strategy' => 'root_constraint_remediation',
                'targets' => new UpgradeTargetSet(array_merge($baseTargets, [$remediations[0]]), $stage->analysisPhp()),
                'with_all_dependencies' => false,
            ];
            $definitions[] = [
                'strategy' => 'root_and_locked_package_remediation',
                'targets' => new UpgradeTargetSet(array_merge($baseTargets, $remediations), $stage->analysisPhp()),
                'with_all_dependencies' => true,
            ];
        }

        // The cap applies to every branch, so no vocabulary or constant change can
        // let one path publish more attempts than the declared budget allows.
        return array_slice($definitions, 0, StagedAnalysisPolicy::MAX_ATTEMPTS_PER_STAGE);
    }

    /**
     * Bound every process the staged chain can cause, including one `composer prohibits`
     * diagnostic for each target after a failed attempt. Earlier stages must succeed to
     * advance, so their last attempt does not add diagnostics; the stopping stage may.
     *
     * @param list<FrameworkStageTarget> $stages
     */
    public function projectedWorstCaseComposerProcesses(array $stages): int
    {
        $successfulPrefixProcesses = 0;
        $worst = 0;

        foreach ($stages as $stage) {
            $definitions = $this->definitionsFor($stage);
            $scenarioProcesses = count($definitions);
            $diagnosticProcesses = array_sum(array_map(
                static fn (array $definition): int => count($definition['targets']),
                $definitions
            ));
            $stopAtThisStage = $successfulPrefixProcesses + $scenarioProcesses + $diagnosticProcesses;
            $worst = max($worst, $stopAtThisStage);

            $last = count($definitions) - 1;
            $successfulPrefixProcesses += $scenarioProcesses + array_sum(array_map(
                static fn (array $definition): int => count($definition['targets']),
                array_slice($definitions, 0, $last)
            ));
        }

        return max($worst, $successfulPrefixProcesses);
    }
}
