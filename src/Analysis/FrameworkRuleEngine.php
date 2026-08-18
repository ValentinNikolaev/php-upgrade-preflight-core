<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Framework\CompatibilityRule;
use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Framework\FrameworkTransitionProvider;
use PhpUpgradePreflight\Core\Framework\HopAwareCompatibilityRule;
use PhpUpgradePreflight\Core\Framework\PackageFamilyClassifier;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkGuidance;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

final class FrameworkRuleEngine
{
    /** @var list<FrameworkIntegration> */
    private array $frameworks;

    /** @param list<FrameworkIntegration> $frameworks */
    public function __construct(array $frameworks = [])
    {
        $this->frameworks = array_values($frameworks);
    }

    /** @return list<FrameworkIntegration> */
    public function activeIntegrations(ProjectState $project, UpgradeRequest $request): array
    {
        $requested = array_values(array_unique(array_map('strtolower', $request->frameworks())));
        $available = array_map(static fn (FrameworkIntegration $framework): string => strtolower($framework->name()), $this->frameworks);
        $unavailable = array_values(array_diff($requested, $available));

        if ($unavailable !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Requested framework integration%s unavailable: %s.',
                count($unavailable) === 1 ? ' is' : 's are',
                implode(', ', $unavailable)
            ));
        }

        $active = [];

        foreach ($this->frameworks as $framework) {
            if ($requested !== [] && !in_array(strtolower($framework->name()), $requested, true)) {
                continue;
            }

            if ($requested !== [] || $framework->detect($project)->isDetected()) {
                $active[] = $framework;
            }
        }

        return $active;
    }

    /** @param list<FrameworkIntegration> $frameworks @return list<string> */
    public function sourcePaths(ProjectState $project, UpgradeRequest $request, array $frameworks): array
    {
        if ($request->sourcePaths() !== []) {
            return $request->sourcePaths();
        }

        $paths = [];
        foreach ($frameworks as $framework) {
            $paths = array_merge($paths, $framework->defaultSourcePaths($project));
        }

        return $paths !== [] ? array_values(array_unique($paths)) : ['src', 'app', 'config', 'routes', 'tests'];
    }

    /**
     * @param list<FrameworkIntegration> $frameworks
     * @return list<PackageFamilyClassifier>
     */
    public function packageFamilyClassifiers(array $frameworks): array
    {
        return array_values(array_filter(
            $frameworks,
            static fn (FrameworkIntegration $framework): bool => $framework instanceof PackageFamilyClassifier
        ));
    }

    /**
     * @param list<FrameworkIntegration> $frameworks
     * @return list<FrameworkGuidance>
     */
    public function assessTransitions(
        array $frameworks,
        ProjectState $project,
        UpgradeRequest $request,
        EvidenceLedger $evidence
    ): array {
        $guidance = [];

        foreach ($frameworks as $framework) {
            if ($framework instanceof FrameworkTransitionProvider) {
                $assessment = $framework->assessTransition($project, $request, $evidence);
                if ($assessment !== null) {
                    $guidance[] = $assessment;
                }
            }
        }

        usort($guidance, static function (FrameworkGuidance $left, FrameworkGuidance $right): int {
            return [
                $left->framework(),
                $left->sourceMajor() ?? PHP_INT_MAX,
                $left->targetMajor() ?? PHP_INT_MAX,
            ] <=> [
                $right->framework(),
                $right->sourceMajor() ?? PHP_INT_MAX,
                $right->targetMajor() ?? PHP_INT_MAX,
            ];
        });

        return $guidance;
    }

    /**
     * Runs every active integration's compatibility rules.
     *
     * Adapter rules are third-party input, so a rule that throws or returns a
     * finding the report models reject is contained the way {@see StagePlanResolver}
     * contains a failing stage provider: the rule is skipped, its failure becomes
     * evidence-backed uncertainty naming the adapter and rule, and every remaining
     * rule, integration, and report section is still produced.
     *
     * @param list<FrameworkIntegration> $frameworks
     * @param list<SourceUsage> $sourceUsages
     * @param list<FrameworkGuidance> $guidance
     * @param list<string> $uncertainties
     * @return list<CompatibilityFinding>
     */
    public function evaluate(
        array $frameworks,
        ProjectState $project,
        UpgradeRequest $request,
        EvidenceLedger $evidence,
        array $sourceUsages = [],
        array $guidance = [],
        ?string $composerVersion = null,
        array &$uncertainties = []
    ): array {
        $findings = [];
        $guidanceByFramework = [];
        foreach ($guidance as $assessment) {
            $guidanceByFramework[strtolower($assessment->framework())] = $assessment;
        }

        foreach ($frameworks as $framework) {
            $assessment = $guidanceByFramework[strtolower($framework->name())] ?? null;
            $evidenceBeforeRules = $this->registeredEvidenceIds($evidence);

            try {
                foreach ($framework->rules() as $rule) {
                    $ruleClass = get_class($rule);
                    $evidenceBeforeRule = $this->registeredEvidenceIds($evidence);

                    try {
                        $findings = array_merge($findings, $this->evaluateRule(
                            $rule,
                            $assessment,
                            $project,
                            $request,
                            $evidence,
                            $sourceUsages,
                            $composerVersion
                        ));
                    } catch (\Throwable $exception) {
                        $uncertainties[] = $this->recordRuleFailure(
                            $framework,
                            $ruleClass,
                            $exception,
                            $evidence,
                            $evidenceBeforeRule
                        );
                    }
                }
            } catch (\Throwable $exception) {
                $uncertainties[] = $this->recordRuleFailure(
                    $framework,
                    null,
                    $exception,
                    $evidence,
                    $evidenceBeforeRules
                );
            }
        }

        return $this->deduplicateFindings($findings);
    }

    /**
     * @param list<SourceUsage> $sourceUsages
     * @return list<CompatibilityFinding>
     */
    private function evaluateRule(
        CompatibilityRule $rule,
        ?FrameworkGuidance $assessment,
        ProjectState $project,
        UpgradeRequest $request,
        EvidenceLedger $evidence,
        array $sourceUsages,
        ?string $composerVersion
    ): array {
        $findings = [];

        if ($rule instanceof HopAwareCompatibilityRule && $assessment !== null) {
            foreach ($assessment->hops() as $hop) {
                if (!$hop->isSupported()) {
                    continue;
                }

                $finding = $rule->evaluateForHop(
                    $project,
                    $request,
                    $evidence,
                    $hop,
                    $composerVersion,
                    $sourceUsages
                );
                if ($finding !== null) {
                    $findings[] = $finding->appliesToHops() === []
                        ? $finding->withAppliesToHops([$hop->reference()])
                        : $finding;
                }
            }

            return $findings;
        }

        $finding = $rule->evaluate($project, $request, $evidence, $sourceUsages);
        if ($finding !== null) {
            $references = $assessment === null ? [] : $assessment->supportedHopReferences();
            $findings[] = $finding->appliesToHops() === [] && count($references) === 1
                ? $finding->withAppliesToHops($references)
                : $finding;
        }

        return $findings;
    }

    /**
     * Records a skipped adapter rule as evidence-backed uncertainty.
     *
     * The failure detail stays in evidence context, which the models redact, while
     * the uncertainty carries only the adapter, the rule, and the evidence IDs.
     * Evidence the rule registered before failing is referenced too, so a half
     * finished rule cannot orphan ledger entries in the assembled report.
     *
     * @param string|null $rule Failing rule class, or null when the rule set itself failed.
     * @param array<string, true> $evidenceBefore
     */
    private function recordRuleFailure(
        FrameworkIntegration $framework,
        ?string $rule,
        \Throwable $exception,
        EvidenceLedger $evidence,
        array $evidenceBefore
    ): string {
        $partialEvidence = $this->newEvidenceReferences($evidence, $evidenceBefore);
        $failureId = $evidence->add(
            'framework-rule',
            Evidence::E2_PACKAGE_METADATA,
            $rule === null
                ? 'A framework adapter failed while listing its compatibility rules.'
                : 'A framework adapter compatibility rule failed and was skipped.',
            'high',
            [
                'framework' => $framework->name(),
                'rule' => $rule,
                'reason' => $rule === null ? 'rule_set_failure' : 'rule_failure',
                'error' => $exception->getMessage(),
            ]
        )->id();
        $references = implode(', ', array_merge($partialEvidence, [$failureId]));

        if ($rule === null) {
            return sprintf(
                'Framework adapter "%s" failed while listing its compatibility rules, so its remaining rules were not evaluated (%s).',
                $framework->name(),
                $references
            );
        }

        return sprintf(
            'Framework adapter "%s" rule "%s" failed and was skipped, so its findings are missing from this report (%s).',
            $framework->name(),
            $rule,
            $references
        );
    }

    /** @return array<string, true> */
    private function registeredEvidenceIds(EvidenceLedger $evidence): array
    {
        return array_fill_keys(array_map(
            static fn (Evidence $item): string => $item->id(),
            $evidence->all()
        ), true);
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

    /**
     * Adjacent rule packs may independently reach the same conclusion. Keep one
     * finding while retaining every evidence record and hop that established it.
     *
     * @param list<CompatibilityFinding> $findings
     * @return list<CompatibilityFinding>
     */
    private function deduplicateFindings(array $findings): array
    {
        $deduplicated = [];
        $indexes = [];

        foreach ($findings as $finding) {
            $key = implode("\0", [
                strtolower($finding->framework()),
                $finding->severity(),
                $finding->summary(),
            ]);
            if (!isset($indexes[$key])) {
                $indexes[$key] = count($deduplicated);
                $deduplicated[] = $finding;

                continue;
            }

            $index = $indexes[$key];
            $existing = $deduplicated[$index];
            $deduplicated[$index] = new CompatibilityFinding(
                $existing->framework(),
                $existing->severity(),
                $existing->summary(),
                array_values(array_unique(array_merge($existing->evidence(), $finding->evidence()))),
                array_merge($existing->appliesToHops(), $finding->appliesToHops())
            );
        }

        return array_values($deduplicated);
    }
}
