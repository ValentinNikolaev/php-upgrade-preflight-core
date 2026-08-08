<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Framework\FrameworkTransitionProvider;
use PhpUpgradePreflight\Core\Framework\PackageFamilyClassifier;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
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
     * @param list<FrameworkIntegration> $frameworks
     * @param list<SourceUsage> $sourceUsages
     * @param list<FrameworkGuidance> $guidance
     * @return list<CompatibilityFinding>
     */
    public function evaluate(
        array $frameworks,
        ProjectState $project,
        UpgradeRequest $request,
        EvidenceLedger $evidence,
        array $sourceUsages = [],
        array $guidance = []
    ): array {
        $findings = [];
        $hopReferences = [];
        foreach ($guidance as $assessment) {
            $hopReferences[$assessment->framework()] = $assessment->supportedHopReferences();
        }

        foreach ($frameworks as $framework) {
            foreach ($framework->rules() as $rule) {
                $finding = $rule->evaluate($project, $request, $evidence, $sourceUsages);
                if ($finding !== null) {
                    $references = $hopReferences[strtolower($finding->framework())] ?? [];
                    $findings[] = $finding->appliesToHops() === [] && count($references) === 1
                        ? $finding->withAppliesToHops($references)
                        : $finding;
                }
            }
        }

        return $findings;
    }
}
