<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class UpgradeReport
{
    public UpgradeRequest $request;
    public ProjectState $projectState;
    /** @var list<ScenarioResult> */
    public array $scenarios;
    public LockDiff $lockDiff;
    /** @var list<Blocker> */
    public array $blockers;
    /** @var list<SourceUsage> */
    public array $sourceImpact;
    /** @var list<CompatibilityFinding> */
    public array $frameworkFindings;
    public RiskSummary $risk;
    public EffortEstimate $effort;
    /** @var list<string> */
    public array $uncertainties;
    /** @var list<Evidence> */
    public array $evidence;

    /**
     * @param list<ScenarioResult> $scenarios
     * @param list<Blocker> $blockers
     * @param list<SourceUsage> $sourceImpact
     * @param list<CompatibilityFinding> $frameworkFindings
     * @param list<string> $uncertainties
     * @param list<Evidence> $evidence
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
        array $evidence
    ) {
        $this->request = $request;
        $this->projectState = $projectState;
        $this->scenarios = array_values($scenarios);
        $this->lockDiff = $lockDiff;
        $this->blockers = array_values($blockers);
        $this->sourceImpact = array_values($sourceImpact);
        $this->frameworkFindings = array_values($frameworkFindings);
        $this->risk = $risk;
        $this->effort = $effort;
        $this->uncertainties = array_values($uncertainties);
        $this->evidence = array_values($evidence);

        $ledger = new EvidenceLedger($this->evidence);
        $ledger->validateReferences($this->evidenceReferences());
    }

    public function resolutionStatus(): string
    {
        foreach ($this->scenarios as $scenario) {
            if ($scenario->succeeded()) {
                return count($this->lockDiff->packageChanges) > 0 ? 'feasible_with_changes' : 'feasible';
            }
        }

        foreach ($this->scenarios as $scenario) {
            if ($scenario->isOperationalFailure()) {
                return 'unknown';
            }
        }

        return count($this->blockers) > 0 ? 'blocked' : 'unknown';
    }

    public function toArray(): array
    {
        return [
            'request_summary' => $this->request->toArray(),
            'project_state' => $this->projectState->toArray(),
            'resolution' => [
                'status' => $this->resolutionStatus(),
                'scenarios' => array_map(static fn (ScenarioResult $scenario): array => $scenario->toArray(), $this->scenarios),
            ],
            'transition' => [
                'package_changes' => array_map(static fn (PackageChange $change): array => $change->toArray(), $this->lockDiff->packageChanges),
                'root_constraint_changes' => [],
            ],
            'blockers' => array_map(static fn (Blocker $blocker): array => $blocker->toArray(), $this->blockers),
            'source_impact' => array_map(static fn (SourceUsage $usage): array => $usage->toArray(), $this->sourceImpact),
            'framework_findings' => array_map(static fn (CompatibilityFinding $finding): array => $finding->toArray(), $this->frameworkFindings),
            'plan' => [
                'stages' => [],
            ],
            'risk' => $this->risk->toArray(),
            'effort' => $this->effort->toArray(),
            'tests' => [],
            'uncertainties' => $this->uncertainties,
            'evidence' => array_map(static fn (Evidence $evidence): array => $evidence->toArray(), $this->evidence),
        ];
    }

    /** @return list<string> */
    private function evidenceReferences(): array
    {
        $references = [];

        foreach ($this->blockers as $index => $blocker) {
            $references = $this->appendFindingReferences($references, $blocker->evidence, sprintf('Blocker at index %d', $index));
        }

        foreach ($this->sourceImpact as $index => $usage) {
            $references = $this->appendFindingReferences($references, $usage->evidence, sprintf('Source usage at index %d', $index));
        }

        foreach ($this->frameworkFindings as $index => $finding) {
            $references = $this->appendFindingReferences($references, $finding->evidence, sprintf('Framework finding at index %d', $index));
        }

        foreach ($this->evidence as $evidence) {
            foreach ($this->uncertainties as $uncertainty) {
                if ($this->containsEvidenceReference($uncertainty, $evidence->id)) {
                    $references[] = $evidence->id;
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
