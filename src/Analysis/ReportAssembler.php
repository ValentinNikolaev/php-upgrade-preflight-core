<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkGuidance;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

final class ReportAssembler
{
    private ReportSectionBuilder $sectionBuilder;
    private SourceImpactBuilder $sourceImpactBuilder;

    public function __construct(
        ?ReportSectionBuilder $sectionBuilder = null,
        ?SourceImpactBuilder $sourceImpactBuilder = null
    ) {
        $this->sectionBuilder = $sectionBuilder ?? new ReportSectionBuilder();
        $this->sourceImpactBuilder = $sourceImpactBuilder ?? new SourceImpactBuilder();
    }

    /**
     * @param list<ScenarioResult> $scenarioResults
     * @param list<Blocker> $blockers
     * @param list<SourceUsage> $sourceImpact
     * @param list<CompatibilityFinding> $frameworkFindings
     * @param list<string> $sourceUncertainties
     * @param list<FrameworkGuidance> $frameworkGuidance
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
        EvidenceLedger $evidence,
        array $frameworkGuidance = []
    ): UpgradeReport {
        $sections = $this->sectionBuilder->build(
            $request,
            $project,
            $scenarioResults,
            $lockDiff,
            $blockers,
            $sourceImpact,
            $frameworkFindings,
            $sourceUncertainties,
            $evidence
        );

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
            $sections->uncertainties(),
            $evidence->all(),
            $sections->rootConstraintChanges(),
            $sections->planStages(),
            $sections->tests(),
            $this->sourceImpactBuilder->build($sourceImpact, $frameworkFindings),
            $frameworkGuidance
        );
    }
}
