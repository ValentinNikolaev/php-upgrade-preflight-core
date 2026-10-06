<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Framework;

use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkHop;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

interface HopAwareCompatibilityRule extends CompatibilityRule
{
    /** @param list<SourceUsage> $sourceUsages */
    public function evaluateForHop(
        ProjectState $project,
        UpgradeRequest $request,
        EvidenceLedger $evidence,
        FrameworkHop $hop,
        ?string $composerVersion = null,
        array $sourceUsages = []
    ): ?CompatibilityFinding;
}
