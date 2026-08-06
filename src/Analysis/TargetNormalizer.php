<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;

final class TargetNormalizer
{
    /** @param list<UpgradeTarget> $targets */
    public function normalize(array $targets, ?string $targetPhp = null): UpgradeTargetSet
    {
        return new UpgradeTargetSet($targets, $targetPhp);
    }
}
