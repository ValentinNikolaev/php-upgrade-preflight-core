<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\TargetPlatform;

final class BlockerGrouper
{
    private ComposerBlockerParser $parser;
    private AbandonedPackageDetector $abandonedPackageDetector;

    public function __construct(
        ?ComposerBlockerParser $parser = null,
        ?AbandonedPackageDetector $abandonedPackageDetector = null
    ) {
        $this->parser = $parser ?? new ComposerBlockerParser();
        $this->abandonedPackageDetector = $abandonedPackageDetector ?? new AbandonedPackageDetector();
    }

    /**
     * @param list<ScenarioResult> $scenarioResults
     * @param array<string, string> $requestedConstraints
     * @return list<Blocker>
     */
    public function group(
        array $scenarioResults,
        EvidenceLedger $evidence,
        ?ComposerLock $metadataLock = null,
        array $requestedConstraints = [],
        ?TargetPlatform $platform = null
    ): array {
        $metadataLock = $metadataLock ?? $this->successfulLock($scenarioResults);
        $blockers = $metadataLock === null
            ? []
            : $this->abandonedPackageDetector->detect($metadataLock, $evidence, $requestedConstraints);
        /** @var array<string, int> $abandonedPackageIndexes */
        $abandonedPackageIndexes = [];
        foreach ($blockers as $index => $blocker) {
            $abandonedPackageIndexes[$blocker->subject()] = $index;
        }

        foreach ($scenarioResults as $result) {
            if ($result->scenario()->determinesTargetFeasibility() && $result->succeeded()) {
                return $blockers;
            }
        }

        /** @var array<string, int> $blockerIndexes */
        $blockerIndexes = [];

        foreach ($scenarioResults as $result) {
            if ($result->scenario()->isBaselineValidation() || !$result->isSolverFailure()) {
                continue;
            }

            $output = trim($result->stdout() . "\n" . $result->stderr());
            $evidenceId = $evidence->add('solver', Evidence::E1_SOLVER, sprintf('Composer scenario "%s" failed.', $result->scenario()->name()), 'high', [
                'scenario' => $result->scenario()->name(),
                'targets' => $result->scenario()->targets()->toArray(),
                'exit_code' => $result->exitCode(),
                'output_excerpt' => substr($output, 0, 2000),
                'diagnostics' => array_map(static fn (ComposerDiagnostic $diagnostic): array => $diagnostic->toArray(), $result->diagnostics()),
            ])->id();

            foreach ($this->parser->parse($result, $evidenceId, $platform) as $blocker) {
                if ($blocker->type() === 'abandoned-package' && isset($abandonedPackageIndexes[$blocker->subject()])) {
                    $index = $abandonedPackageIndexes[$blocker->subject()];
                    $blockers[$index] = $blockers[$index]->withAdditionalEvidence($blocker->evidence());

                    continue;
                }

                $blockerKey = $this->blockerKey($blocker);
                if (isset($blockerIndexes[$blockerKey])) {
                    $index = $blockerIndexes[$blockerKey];
                    $blockers[$index] = $blockers[$index]->withAdditionalEvidence($blocker->evidence());

                    continue;
                }

                $index = count($blockers);
                $blockers[] = $blocker;

                if ($blocker->type() === 'abandoned-package') {
                    $abandonedPackageIndexes[$blocker->subject()] = $index;
                }

                $blockerIndexes[$blockerKey] = $index;
            }
        }

        return $blockers;
    }

    /** @param list<ScenarioResult> $scenarioResults */
    private function successfulLock(array $scenarioResults): ?ComposerLock
    {
        foreach ($scenarioResults as $result) {
            if ($result->scenario()->determinesTargetFeasibility() && $result->succeeded()) {
                return $result->lock();
            }
        }

        return null;
    }

    private function blockerKey(Blocker $blocker): string
    {
        return serialize([
            $blocker->type(),
            $blocker->subject(),
            $blocker->requestedConstraint(),
            $blocker->blocker(),
            $blocker->lockedVersion(),
            $blocker->conflict(),
            $blocker->dependencyPath(),
        ]);
    }
}
