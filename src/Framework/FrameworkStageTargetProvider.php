<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Framework;

use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkStagePlan;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

/**
 * Optional v0.3 extension point. The required v0.2 FrameworkIntegration and
 * FrameworkTransitionProvider interfaces deliberately remain unchanged.
 */
interface FrameworkStageTargetProvider
{
    public function planStages(
        ProjectState $project,
        UpgradeRequest $request,
        EvidenceLedger $evidence
    ): FrameworkStagePlan;
}
