<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Framework;

use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkGuidance;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

interface FrameworkTransitionProvider
{
    public function assessTransition(
        ProjectState $project,
        UpgradeRequest $request,
        EvidenceLedger $evidence
    ): ?FrameworkGuidance;
}
