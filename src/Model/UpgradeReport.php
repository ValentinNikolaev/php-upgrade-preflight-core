<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class UpgradeReport
{
    private ReportMetadata $metadata;
    private UpgradeRequest $request;
    private ProjectState $projectState;
    /** @var list<ScenarioResult> */
    private array $scenarios;
    private LockDiff $lockDiff;
    /** @var list<Blocker> */
    private array $blockers;
    /** @var list<SourceUsage> */
    private array $sourceImpact;
    /** @var list<CompatibilityFinding> */
    private array $frameworkFindings;
    private RiskSummary $risk;
    private EffortEstimate $effort;
    /** @var list<RootConstraintChange> */
    private array $rootConstraintChanges;
    /** @var list<PlanStage> */
    private array $planStages;
    /** @var list<TestGuidance> */
    private array $tests;
    /** @var list<string> */
    private array $uncertainties;
    /** @var list<Evidence> */
    private array $evidence;

    /**
     * @param list<ScenarioResult> $scenarios
     * @param list<Blocker> $blockers
     * @param list<SourceUsage> $sourceImpact
     * @param list<CompatibilityFinding> $frameworkFindings
     * @param list<string> $uncertainties
     * @param list<Evidence> $evidence
     * @param list<RootConstraintChange> $rootConstraintChanges
     * @param list<PlanStage> $planStages
     * @param list<TestGuidance> $tests
     */
    public function __construct(
        UpgradeRequest $request,
        ProjectState $projectState,
        array $scenarios,
        LockDiff $lockDiff,
        array $blockers,
        array $sourceImpact,
        array $frameworkFindings,
        RiskSummary $risk,
        EffortEstimate $effort,
        array $uncertainties,
        array $evidence,
        array $rootConstraintChanges = [],
        array $planStages = [],
        array $tests = []
    ) {
        $this->metadata = new ReportMetadata();
        $this->request = $request;
        $this->projectState = $projectState;
        $this->scenarios = array_values($scenarios);
        $this->lockDiff = $lockDiff;
        $this->blockers = array_values($blockers);
        $this->sourceImpact = array_values($sourceImpact);
        $this->frameworkFindings = array_values($frameworkFindings);
        $this->risk = $risk;
        $this->effort = $effort;
        $this->rootConstraintChanges = array_values($rootConstraintChanges);
        $this->planStages = array_values($planStages);
        $this->tests = array_values($tests);
        $this->uncertainties = array_values($uncertainties);
        $this->evidence = array_values($evidence);

        $ledger = new EvidenceLedger($this->evidence);
        $ledger->validateReferences($this->evidenceReferences());
    }

    public function metadata(): ReportMetadata
    {
        return $this->metadata;
    }

    public function request(): UpgradeRequest
    {
        return $this->request;
    }

    public function projectState(): ProjectState
    {
        return $this->projectState;
    }

    /** @return list<ScenarioResult> */
    public function scenarios(): array
    {
        return $this->scenarios;
    }

    public function lockDiff(): LockDiff
    {
        return $this->lockDiff;
    }

    /** @return list<Blocker> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    /** @return list<SourceUsage> */
    public function sourceImpact(): array
    {
        return $this->sourceImpact;
    }

    /** @return list<CompatibilityFinding> */
    public function frameworkFindings(): array
    {
        return $this->frameworkFindings;
    }

    public function risk(): RiskSummary
    {
        return $this->risk;
    }

    public function effort(): EffortEstimate
    {
        return $this->effort;
    }

    /** @return list<RootConstraintChange> */
    public function rootConstraintChanges(): array
    {
        return $this->rootConstraintChanges;
    }

    /** @return list<PlanStage> */
    public function planStages(): array
    {
        return $this->planStages;
    }

    /** @return list<TestGuidance> */
    public function tests(): array
    {
        return $this->tests;
    }

    /** @return list<string> */
    public function uncertainties(): array
    {
        return $this->uncertainties;
    }

    /** @return list<Evidence> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    public function resolutionStatus(): string
    {
        foreach ($this->scenarios as $scenario) {
            if ($scenario->scenario()->determinesTargetFeasibility() && $scenario->succeeded()) {
                return count($this->lockDiff->packageChanges()) > 0 ? 'feasible_with_changes' : 'feasible';
            }
        }

        foreach ($this->scenarios as $scenario) {
            if ($scenario->scenario()->determinesTargetFeasibility() && $scenario->isOperationalFailure()) {
                return 'unknown';
            }
        }

        foreach ($this->blockers as $blocker) {
            if ($blocker->blocksResolution()) {
                return 'blocked';
            }
        }

        return 'unknown';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metadata' => $this->metadata->toArray(),
            'request_summary' => $this->request->toArray(),
            'project_state' => $this->projectState->toArray(),
            'resolution' => [
                'status' => $this->resolutionStatus(),
                'scenarios' => array_map(static fn (ScenarioResult $scenario): array => $scenario->toArray(), $this->scenarios),
            ],
            'transition' => [
                'package_changes' => array_map(static fn (PackageChange $change): array => $change->toArray(), $this->lockDiff->packageChanges()),
                'root_constraint_changes' => array_map(
                    static fn (RootConstraintChange $change): array => $change->toArray(),
                    $this->rootConstraintChanges
                ),
            ],
            'blockers' => array_map(static fn (Blocker $blocker): array => $blocker->toArray(), $this->blockers),
            'source_impact' => array_map(static fn (SourceUsage $usage): array => $usage->toArray(), $this->sourceImpact),
            'framework_findings' => array_map(static fn (CompatibilityFinding $finding): array => $finding->toArray(), $this->frameworkFindings),
            'plan' => [
                'stages' => array_map(static fn (PlanStage $stage): array => $stage->toArray(), $this->planStages),
            ],
            'risk' => $this->risk->toArray(),
            'effort' => $this->effort->toArray(),
            'tests' => array_map(static fn (TestGuidance $test): array => $test->toArray(), $this->tests),
            'uncertainties' => $this->uncertainties,
            'evidence' => array_map(static fn (Evidence $evidence): array => $evidence->toArray(), $this->evidence),
        ];
    }

    /** @return list<string> */
    private function evidenceReferences(): array
    {
        $references = [];

        foreach ($this->blockers as $index => $blocker) {
            $references = $this->appendFindingReferences($references, $blocker->evidence(), sprintf('Blocker at index %d', $index));
        }

        foreach ($this->sourceImpact as $index => $usage) {
            $references = $this->appendFindingReferences($references, $usage->evidence(), sprintf('Source usage at index %d', $index));
        }

        foreach ($this->frameworkFindings as $index => $finding) {
            $references = $this->appendFindingReferences($references, $finding->evidence(), sprintf('Framework finding at index %d', $index));
        }

        foreach ($this->rootConstraintChanges as $index => $change) {
            $references = $this->appendFindingReferences($references, $change->evidence(), sprintf('Root constraint change at index %d', $index));
        }

        foreach ($this->planStages as $index => $stage) {
            $references = $this->appendFindingReferences($references, $stage->evidence(), sprintf('Plan stage at index %d', $index));
        }

        foreach ($this->evidence as $evidence) {
            foreach ($this->uncertainties as $uncertainty) {
                if ($this->containsEvidenceReference($uncertainty, $evidence->id())) {
                    $references[] = $evidence->id();
                    break;
                }
            }
        }

        return $references;
    }

    /** @param list<string> $references @param list<string> $findingReferences @return list<string> */
    private function appendFindingReferences(array $references, array $findingReferences, string $description): array
    {
        if ($findingReferences === []) {
            throw new \LogicException($description . ' must reference at least one evidence item.');
        }

        foreach ($findingReferences as $reference) {
            $references[] = $reference;
        }

        return $references;
    }

    private function containsEvidenceReference(string $text, string $id): bool
    {
        return preg_match(
            '/(?<![A-Za-z0-9_-])' . preg_quote($id, '/') . '(?![A-Za-z0-9_-])/',
            $text
        ) === 1;
    }
}
