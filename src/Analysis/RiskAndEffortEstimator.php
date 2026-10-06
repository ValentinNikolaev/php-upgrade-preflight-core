<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\Confidence;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Severity;
use PhpUpgradePreflight\Core\Model\StageAnalysis;
use PhpUpgradePreflight\Core\Model\StageBlockerEntry;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\SourceImpactFinding;

/**
 * Produces the report's headline risk level and effort range.
 *
 * Two risk rule sets exist on purpose and must not be merged:
 *
 * - The whole-upgrade rule set (`estimateRisk`) grades an aggregate. It reaches
 *   `high` only when Composer resolution is blocked or weighted source impact is
 *   large, and needs a meaningful volume of changes or findings before it leaves
 *   `low`, so an ordinary transition is not reported as risky.
 * - The stage rule set (`estimateStageRisk`) grades one hop of a staged plan,
 *   where the population is already narrow. It reaches `high` on any stage-level
 *   driver, including an unusable resolution status or a high-severity framework
 *   finding, and leaves `low` as soon as the stage carries any change, finding,
 *   or source weight at all.
 *
 * The two share only the weighted source-impact escalation threshold. The scale
 * of the input is what differs, so the thresholds are named separately below.
 */
final class RiskAndEffortEstimator
{
    /** Weighted source impact that escalates to high risk in both rule sets. */
    private const HIGH_RISK_SOURCE_WEIGHT = 10;

    /** Whole-upgrade rule set: weighted source impact that reaches medium risk and records a driver. */
    private const MEDIUM_RISK_SOURCE_WEIGHT = 4;

    /** Whole-upgrade rule set: package-change count above which risk reaches medium. */
    private const MEDIUM_RISK_CHANGE_COUNT = 10;

    /** Whole-upgrade rule set: package-change count above which the large-transition driver is recorded. */
    private const LARGE_TRANSITION_CHANGE_COUNT = 20;

    /** Whole-upgrade rule set: framework-finding count above which risk reaches medium. */
    private const MEDIUM_RISK_FINDING_COUNT = 2;

    /** Weighted source impact contributed by each unique framework finding. */
    private const FINDING_SOURCE_WEIGHT = 2;

    /** Weighted source impact contributed by a source-impact finding's own severity. */
    private const SEVERITY_WEIGHTS = [
        Severity::LOW => 1,
        Severity::MEDIUM => 2,
        Severity::HIGH => 4,
    ];

    private const DEFAULT_SEVERITY_WEIGHT = 1;

    /** Upper bound on the weight a single finding gains from repeated occurrences. */
    private const MAXIMUM_OCCURRENCE_WEIGHT = 3;

    /** Minimum and maximum hours reported when no effort is inferred. */
    private const NO_EFFORT_HOURS = [0, 0];

    /** Minimum and maximum dependency hours once any blocker is present. */
    private const BLOCKED_DEPENDENCY_HOURS = [3, 8];

    /** Minimum and maximum hours reserved for tests and debugging. */
    private const TEST_HOURS = [2, 8];

    private const DEPENDENCY_MINIMUM_HOURS = 1;
    private const DEPENDENCY_MAXIMUM_FLOOR = 2;
    private const DEPENDENCY_MAXIMUM_CEILING = 8;

    private const SOURCE_MINIMUM_HOURS = 1;
    private const SOURCE_MAXIMUM_FLOOR = 3;
    private const SOURCE_MAXIMUM_CEILING = 16;

    /**
     * @param list<Blocker> $blockers
     * @param list<PackageChange> $changes
     * @param list<CompatibilityFinding> $findings
     * @param list<SourceImpactFinding> $sourceImpact
     */
    public function estimateRisk(array $blockers, array $changes, array $findings, array $sourceImpact = []): RiskSummary
    {
        $drivers = [];
        $hasResolutionBlocker = $this->hasResolutionBlocker($blockers);
        $hasAdvisory = $this->hasAdvisory($blockers);
        $sourceWeight = $this->actionableWeight($sourceImpact);

        if ($hasResolutionBlocker) {
            $drivers[] = 'Composer resolution is blocked.';
        }
        if ($hasAdvisory) {
            $drivers[] = 'Abandoned packages require replacement or removal.';
        }
        if (count($changes) > self::LARGE_TRANSITION_CHANGE_COUNT) {
            $drivers[] = 'Large lockfile transition.';
        }
        if ($findings !== []) {
            $drivers[] = 'Framework compatibility findings require review.';
        }
        if ($sourceWeight >= self::MEDIUM_RISK_SOURCE_WEIGHT) {
            $drivers[] = 'Weighted actionable source findings require review.';
        }

        return new RiskSummary(
            $this->upgradeRiskLevel($hasResolutionBlocker, $hasAdvisory, count($changes), count($findings), $sourceWeight),
            $drivers
        );
    }

    /**
     * @param list<Blocker> $blockers
     * @param list<PackageChange> $changes
     * @param list<SourceImpactFinding> $sourceImpact
     * @param list<CompatibilityFinding> $findings
     */
    public function estimateEffort(
        array $blockers,
        array $changes,
        array $sourceImpact,
        array $findings
    ): EffortEstimate {
        $dependency = $this->dependencyEffort($blockers !== [], count($changes));
        $source = $this->sourceEffort($this->actionableWeight($sourceImpact) + count($findings) * self::FINDING_SOURCE_WEIGHT);
        $tests = self::TEST_HOURS;

        return new EffortEstimate(
            $this->totalEffort($dependency, $source, $tests),
            Confidence::LOW,
            [
                'dependency_resolution' => $dependency,
                'source_changes' => $source,
                'tests_and_debugging' => $tests,
            ],
            ['Estimate is heuristic until project-specific tests and Composer solver output are reviewed.']
        );
    }

    /**
     * @param list<StageBlockerEntry> $blockers
     * @param list<CompatibilityFinding> $findings
     * @param list<SourceImpactFinding> $sourceImpact
     */
    public function estimateStageRisk(
        StageAnalysis $stage,
        array $blockers,
        array $findings,
        array $sourceImpact
    ): RiskSummary {
        $stageId = $stage->target()->id();
        $drivers = [];
        if (in_array($stage->resolutionStatus(), [StagedResolution::BLOCKED, StagedResolution::UNKNOWN], true)) {
            $drivers[] = sprintf('Stage %s did not produce a selectable Composer state.', $stageId);
        }
        foreach ($blockers as $blocker) {
            if ($blocker->isBlocking() && $blocker->isActive()) {
                $drivers[] = sprintf('Stage %s retains an active Composer blocker.', $stageId);
                break;
            }
        }
        foreach ($findings as $finding) {
            if ($finding->severity() === Severity::HIGH) {
                $drivers[] = sprintf('Stage %s: %s', $stageId, $finding->summary());
            }
        }

        return new RiskSummary(
            $this->stageRiskLevel(
                $drivers !== [],
                $stage->packageChanges() !== [],
                $findings !== [],
                $this->actionableWeight($sourceImpact)
            ),
            array_values(array_unique($drivers))
        );
    }

    /**
     * @param list<StageBlockerEntry> $blockers
     * @param list<CompatibilityFinding> $findings
     * @param list<SourceImpactFinding> $sourceImpact
     */
    public function estimateStageEffort(
        StageAnalysis $stage,
        array $blockers,
        array $findings,
        array $sourceImpact
    ): EffortEstimate {
        $activeBlockers = array_filter(
            $blockers,
            static fn (StageBlockerEntry $blocker): bool => $blocker->isBlocking() && $blocker->isActive()
        );
        if ($stage->executionState() === StageAnalysis::SKIPPED) {
            return new EffortEstimate(self::NO_EFFORT_HOURS, Confidence::LOW, ['not_estimated' => self::NO_EFFORT_HOURS], [
                sprintf('Stage %s was skipped, so no application-change effort is inferred.', $stage->target()->id()),
            ]);
        }

        $dependency = $this->dependencyEffort($activeBlockers !== [], count($stage->packageChanges()));
        $source = $this->optionalSourceEffort(
            $this->actionableWeight($sourceImpact)
            + count($this->uniqueFindings($findings)) * self::FINDING_SOURCE_WEIGHT
        );
        $tests = self::TEST_HOURS;

        return new EffortEstimate(
            $this->totalEffort($dependency, $source, $tests),
            Confidence::LOW,
            [
                'dependency_resolution' => $dependency,
                'source_changes' => $source,
                'tests_and_debugging' => $tests,
            ],
            [sprintf(
                'Stage %s is estimated from unique package and original-snapshot findings; Composer attempt count is excluded.',
                $stage->target()->id()
            )]
        );
    }

    /**
     * @param list<Blocker> $blockers
     * @param list<PackageChange> $changes
     * @param list<CompatibilityFinding> $findings
     * @param list<SourceImpactFinding> $sourceImpact
     */
    public function estimateAggregateRisk(
        array $blockers,
        array $changes,
        array $findings,
        array $sourceImpact,
        StagedResolution $staged
    ): RiskSummary {
        $risk = $this->estimateRisk(
            $blockers,
            $this->uniqueChanges(array_merge($changes, ...array_map(
                static fn (StageAnalysis $stage): array => $stage->packageChanges(),
                $staged->stages()
            ))),
            $this->uniqueFindings($findings),
            $this->uniqueSourceImpact(array_merge($sourceImpact, $staged->sourceImpact()))
        );
        $drivers = $risk->drivers();
        foreach ($staged->blockerRegistry() as $blocker) {
            if ($blocker->isBlocking() && $blocker->isActive()) {
                $drivers[] = sprintf('Executed stage %s retains an active Composer blocker.', $blocker->stageId());

                return new RiskSummary(Severity::HIGH, array_values(array_unique($drivers)));
            }
        }

        return new RiskSummary($risk->level(), array_values(array_unique($drivers)));
    }

    /**
     * @param list<Blocker> $blockers
     * @param list<PackageChange> $changes
     * @param list<SourceImpactFinding> $sourceImpact
     * @param list<CompatibilityFinding> $findings
     */
    public function estimateAggregateEffort(
        array $blockers,
        array $changes,
        array $sourceImpact,
        array $findings,
        StagedResolution $staged
    ): EffortEstimate {
        $allChanges = $this->uniqueChanges(array_merge($changes, ...array_map(
            static fn (StageAnalysis $stage): array => $stage->packageChanges(),
            $staged->stages()
        )));
        $allImpact = $this->uniqueSourceImpact(array_merge($sourceImpact, $staged->sourceImpact()));
        $allFindings = $this->uniqueFindings($findings);
        $hasStageBlocker = false;
        foreach ($staged->blockerRegistry() as $blocker) {
            if ($blocker->isBlocking() && $blocker->isActive()) {
                $hasStageBlocker = true;
                break;
            }
        }

        $dependency = $this->dependencyEffort($blockers !== [] || $hasStageBlocker, count($allChanges));
        $source = $this->optionalSourceEffort(
            $this->actionableWeight($allImpact) + count($allFindings) * self::FINDING_SOURCE_WEIGHT
        );
        $tests = self::TEST_HOURS;

        return new EffortEstimate(
            $this->totalEffort($dependency, $source, $tests),
            Confidence::LOW,
            [
                'dependency_resolution' => $dependency,
                'source_changes' => $source,
                'tests_and_debugging' => $tests,
            ],
            ['Aggregate effort counts each exact package transition, framework finding, and source occurrence once; scenario and repeated-hop counts are excluded.']
        );
    }

    /**
     * Whole-upgrade risk rule set. See the class docblock for why it differs from
     * the stage rule set.
     */
    private function upgradeRiskLevel(
        bool $hasResolutionBlocker,
        bool $hasAdvisory,
        int $changeCount,
        int $findingCount,
        int $sourceWeight
    ): string {
        if ($hasResolutionBlocker || $sourceWeight >= self::HIGH_RISK_SOURCE_WEIGHT) {
            return Severity::HIGH;
        }

        if ($hasAdvisory
            || $changeCount > self::MEDIUM_RISK_CHANGE_COUNT
            || $findingCount > self::MEDIUM_RISK_FINDING_COUNT
            || $sourceWeight >= self::MEDIUM_RISK_SOURCE_WEIGHT) {
            return Severity::MEDIUM;
        }

        return Severity::LOW;
    }

    /**
     * Single-stage risk rule set. See the class docblock for why it differs from
     * the whole-upgrade rule set.
     */
    private function stageRiskLevel(
        bool $hasStageDriver,
        bool $hasPackageChanges,
        bool $hasFindings,
        int $sourceWeight
    ): string {
        if ($hasStageDriver || $sourceWeight >= self::HIGH_RISK_SOURCE_WEIGHT) {
            return Severity::HIGH;
        }

        if ($hasPackageChanges || $hasFindings || $sourceWeight > 0) {
            return Severity::MEDIUM;
        }

        return Severity::LOW;
    }

    /** @return array{0:int,1:int} */
    private function dependencyEffort(bool $isBlocked, int $changeCount): array
    {
        if ($isBlocked) {
            return self::BLOCKED_DEPENDENCY_HOURS;
        }

        return [
            self::DEPENDENCY_MINIMUM_HOURS,
            max(self::DEPENDENCY_MAXIMUM_FLOOR, min(self::DEPENDENCY_MAXIMUM_CEILING, $changeCount)),
        ];
    }

    /** @return array{0:int,1:int} */
    private function sourceEffort(int $sourceWeight): array
    {
        return [
            self::SOURCE_MINIMUM_HOURS,
            max(self::SOURCE_MAXIMUM_FLOOR, min(self::SOURCE_MAXIMUM_CEILING, $sourceWeight)),
        ];
    }

    /**
     * Stage and aggregate estimates report no source effort when nothing weighted
     * was observed. The whole-upgrade estimate always reserves a review minimum.
     *
     * @return array{0:int,1:int}
     */
    private function optionalSourceEffort(int $sourceWeight): array
    {
        return $sourceWeight === 0 ? self::NO_EFFORT_HOURS : $this->sourceEffort($sourceWeight);
    }

    /**
     * @param array{0:int,1:int} $dependency
     * @param array{0:int,1:int} $source
     * @param array{0:int,1:int} $tests
     * @return array{0:int,1:int}
     */
    private function totalEffort(array $dependency, array $source, array $tests): array
    {
        return [
            $dependency[0] + $source[0] + $tests[0],
            $dependency[1] + $source[1] + $tests[1],
        ];
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

    /** @param list<Blocker> $blockers */
    private function hasAdvisory(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (!$blocker->blocksResolution()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<SourceImpactFinding> $findings */
    private function actionableWeight(array $findings): int
    {
        $weight = 0;
        foreach ($findings as $finding) {
            $findingWeight = self::SEVERITY_WEIGHTS[$finding->severity()] ?? self::DEFAULT_SEVERITY_WEIGHT;
            if ($finding->relevance() === 'package_change_and_framework_rule') {
                ++$findingWeight;
            }
            if ($finding->ownership() !== 'exact') {
                ++$findingWeight;
            }

            $findingWeight += min(self::MAXIMUM_OCCURRENCE_WEIGHT, max(0, count($finding->occurrences()) - 1));
            $weight += $findingWeight;
        }

        return $weight;
    }

    /**
     * @param list<PackageChange> $changes
     * @return list<PackageChange>
     */
    private function uniqueChanges(array $changes): array
    {
        $unique = [];
        foreach ($changes as $change) {
            $unique[serialize($change->toArray())] = $change;
        }

        return array_values($unique);
    }

    /**
     * @param list<CompatibilityFinding> $findings
     * @return list<CompatibilityFinding>
     */
    private function uniqueFindings(array $findings): array
    {
        $unique = [];
        foreach ($findings as $finding) {
            $unique[serialize([
                $finding->framework(),
                $finding->severity(),
                $finding->summary(),
            ])] = $finding;
        }

        return array_values($unique);
    }

    /**
     * @param list<SourceImpactFinding> $findings
     * @return list<SourceImpactFinding>
     */
    private function uniqueSourceImpact(array $findings): array
    {
        $unique = [];
        foreach ($findings as $finding) {
            $unique[$finding->id()] = isset($unique[$finding->id()])
                ? $unique[$finding->id()]->merge($finding)
                : $finding;
        }

        return array_values($unique);
    }
}
