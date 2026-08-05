<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Contracts;

use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;

interface UpgradeAnalyzer
{
    public function analyzeUpgrade(UpgradeRequest $request): UpgradeReport;
}
