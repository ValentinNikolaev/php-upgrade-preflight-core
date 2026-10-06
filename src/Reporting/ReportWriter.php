<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Reporting;

use PhpUpgradePreflight\Core\Model\UpgradeReport;

/**
 * Renders a canonical {@see UpgradeReport} into one serialized representation.
 *
 * Implementations are projections of the same report: they must not add
 * analysis, filtering, or thresholds of their own.
 */
interface ReportWriter
{
    public function render(UpgradeReport $report): string;
}
