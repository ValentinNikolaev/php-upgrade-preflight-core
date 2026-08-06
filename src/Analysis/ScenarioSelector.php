<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;

final class ScenarioSelector
{
    /** @param list<string> $uncertainties @return list<Scenario> */
    public function select(
        UpgradeTargetSet $targets,
        ?string $fromPhp,
        ?string $projectPlatformPhp,
        array &$uncertainties
    ): array {
        $candidates = [
            new Scenario('baseline-validation', $targets, false, false, true),
            new Scenario('exact-target', $targets, false, false),
            new Scenario('target-with-all-dependencies', $targets, true, false),
            new Scenario('minimal-changes', $targets, true, true),
        ];

        if ($targets->targetPhp() !== null && $targets->packageTargets() !== []) {
            $candidates[] = new Scenario(
                'target-platform-only',
                new UpgradeTargetSet([], $targets->targetPhp()),
                false,
                false,
                false,
                false
            );

            $sourcePhp = $fromPhp ?? $projectPlatformPhp;
            if ($sourcePhp === null) {
                $uncertainties[] = 'The staged package-target scenario was skipped because the current project PHP version is unknown; supply --from-php or configure config.platform.php.';
            } else {
                $candidates[] = new Scenario(
                    'staged-targets',
                    new UpgradeTargetSet($targets->packageTargets(), $sourcePhp),
                    true,
                    false,
                    false,
                    false
                );
            }
        }

        return $this->uniqueScenarios($candidates);
    }

    /** @param list<Scenario> $candidates @return list<Scenario> */
    private function uniqueScenarios(array $candidates): array
    {
        $selected = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $key = $this->executionKey($candidate);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $selected[] = $candidate;
        }

        return $selected;
    }

    private function executionKey(Scenario $scenario): string
    {
        if ($scenario->isBaselineValidation()) {
            return 'baseline-validation';
        }

        $hasPackageTargets = $scenario->targets()->packageTargets() !== [];

        return json_encode([
            'targets' => $scenario->targets()->toArray(),
            // Composer only expands -W from the packages in a partial-update argument list.
            'with_all_dependencies' => $hasPackageTargets && $scenario->withAllDependencies(),
            'minimal_changes' => $scenario->minimalChanges(),
        ], JSON_THROW_ON_ERROR);
    }
}
