<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Framework;

use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

interface CompatibilityRule
{
    /** @param list<Evidence> $evidence */
    public function evaluate(ProjectState $project, UpgradeRequest $request, array &$evidence): ?CompatibilityFinding;
}
