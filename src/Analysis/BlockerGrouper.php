<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\ScenarioResult;

final class BlockerGrouper
{
    /**
     * @param list<ScenarioResult> $scenarioResults
     * @return list<Blocker>
     */
    public function group(array $scenarioResults, EvidenceLedger $evidence): array
    {
        foreach ($scenarioResults as $result) {
            if ($result->succeeded()) {
                return [];
            }
        }

        $blockers = [];

        foreach ($scenarioResults as $result) {
            if (!$result->isSolverFailure()) {
                continue;
            }

            $output = trim($result->stdout . "\n" . $result->stderr);
            $evidenceId = $evidence->add('solver', Evidence::E1_SOLVER, sprintf('Composer scenario "%s" failed.', $result->scenario->name), 'high', [
                'exit_code' => $result->exitCode,
                'output_excerpt' => substr($output, 0, 2000),
            ])->id;

            $blockers[] = $this->fromOutput($output, $evidenceId);
        }

        return $blockers;
    }

    private function fromOutput(string $output, string $evidenceId): Blocker
    {
        if (stripos($output, 'requires php') !== false || stripos($output, 'php version') !== false) {
            return new Blocker('php-platform-too-low', 'php', 'Composer reported a PHP platform version conflict.', 'medium', [$evidenceId]);
        }

        if (stripos($output, 'could not find package') !== false) {
            return new Blocker('package-not-found', 'composer', 'Composer could not find one of the requested packages.', 'medium', [$evidenceId]);
        }

        if (stripos($output, 'minimum-stability') !== false) {
            return new Blocker('minimum-stability-conflict', 'composer', 'Composer reported a minimum-stability conflict.', 'medium', [$evidenceId]);
        }

        if (preg_match('/- Root composer\\.json requires ([^\\s]+)/i', $output, $matches) === 1) {
            return new Blocker('root-constraint-conflict', strtolower($matches[1]), 'Root Composer constraints conflict with the requested target.', 'medium', [$evidenceId]);
        }

        return new Blocker('unknown-composer-failure', 'composer', 'Composer failed, but the blocker type is not yet classified.', 'low', [$evidenceId]);
    }
}
