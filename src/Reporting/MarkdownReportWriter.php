<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Reporting;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\UpgradeReport;

final class MarkdownReportWriter
{
    public function render(UpgradeReport $report): string
    {
        $lines = [
            '# PHP Upgrade Preflight Report',
            '',
            'Resolution: **' . $report->resolutionStatus() . '**',
            '',
            '## Blockers',
        ];

        if ($report->blockers === []) {
            $lines[] = '- None detected.';
        } else {
            foreach ($report->blockers as $blocker) {
                $lines[] = sprintf('- `%s` %s (%s)', $blocker->type, $blocker->summary, implode(', ', $blocker->evidence));
            }
        }

        $lines[] = '';
        $lines[] = '## Package Changes';

        if ($report->lockDiff->packageChanges === []) {
            $lines[] = '- No lockfile changes detected.';
        } else {
            foreach ($report->lockDiff->packageChanges as $change) {
                $lines[] = sprintf('- `%s`: %s `%s` -> `%s`', $change->name, $change->changeType, $change->fromVersion ?? '-', $change->toVersion ?? '-');
            }
        }

        $lines[] = '';
        $lines[] = '## Framework Findings';

        if ($report->frameworkFindings === []) {
            $lines[] = '- None detected.';
        } else {
            foreach ($report->frameworkFindings as $finding) {
                $lines[] = sprintf('- `%s` %s (%s)', $finding->severity, $finding->summary, implode(', ', $finding->evidence));
            }
        }

        $lines[] = '';
        $lines[] = '## Risk And Effort';
        $lines[] = sprintf('- Risk: `%s`', $report->risk->level);
        $lines[] = sprintf('- Effort: `%d-%d` hours (%s confidence)', $report->effort->rangeHours[0], $report->effort->rangeHours[1], $report->effort->confidence);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
