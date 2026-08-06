<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

final class ReportAssembler
{
    /**
     * @param list<ScenarioResult> $scenarioResults
     * @param list<Blocker> $blockers
     * @param list<SourceUsage> $sourceImpact
     * @param list<CompatibilityFinding> $frameworkFindings
     * @param list<string> $sourceUncertainties
     */
    public function assemble(
        UpgradeRequest $request,
        ProjectState $project,
        array $scenarioResults,
        LockDiff $lockDiff,
        array $blockers,
        array $sourceImpact,
        array $frameworkFindings,
        RiskSummary $risk,
        EffortEstimate $effort,
        array $sourceUncertainties,
        EvidenceLedger $evidence
    ): UpgradeReport {
        return new UpgradeReport(
            $request,
            $project,
            $scenarioResults,
            $lockDiff,
            $blockers,
            $sourceImpact,
            $frameworkFindings,
            $risk,
            $effort,
            $this->uncertainties($scenarioResults, $sourceUncertainties),
            $evidence->all()
        );
    }

    /** @param list<ScenarioResult> $scenarioResults @param list<string> $sourceUncertainties @return list<string> */
    private function uncertainties(array $scenarioResults, array $sourceUncertainties): array
    {
        $uncertainties = $sourceUncertainties;
        foreach ($scenarioResults as $result) {
            if ($result->isOperationalFailure()) {
                $uncertainties[] = sprintf(
                    'Composer scenario "%s" could not complete because of an analysis-environment failure.',
                    $result->scenario->name
                );
            }
        }

        return array_values(array_unique($uncertainties));
    }
}
