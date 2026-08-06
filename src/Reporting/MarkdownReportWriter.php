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
            '## Composer Scenarios',
        ];

        if ($report->scenarios === []) {
            $lines[] = '- None executed.';
        } else {
            foreach ($report->scenarios as $scenario) {
                $lines[] = sprintf(
                    '- `%s`: %s (exit `%d`, failure type `%s`)',
                    $this->inline($scenario->scenario->name),
                    $scenario->succeeded() ? 'succeeded' : 'failed',
                    $scenario->exitCode,
                    $scenario->failureType ?? 'none'
                );

                if (trim($scenario->stdout) !== '') {
                    $lines[] = sprintf('  - stdout: `%s`', $this->inline($this->excerpt($scenario->stdout)));
                }
                if (trim($scenario->stderr) !== '') {
                    $lines[] = sprintf('  - stderr: `%s`', $this->inline($this->excerpt($scenario->stderr)));
                }
            }
        }

        $lines[] = '';
        $lines[] = '## Blockers';

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
        $lines[] = '## Source Impact';

        if ($report->sourceImpact === []) {
            $lines[] = '- None detected.';
        } else {
            foreach ($report->sourceImpact as $usage) {
                $location = $usage->line === null ? $usage->file : sprintf('%s:%d', $usage->file, $usage->line);
                $lines[] = sprintf(
                    '- `%s` `%s` in `%s` (%s)',
                    $this->inline($usage->usageType),
                    $this->inline($usage->symbol),
                    $this->inline($location),
                    implode(', ', $usage->evidence)
                );
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

        $lines[] = '';
        $lines[] = '## Uncertainties';

        if ($report->uncertainties === []) {
            $lines[] = '- None recorded.';
        } else {
            foreach ($report->uncertainties as $uncertainty) {
                $lines[] = '- ' . $this->singleLine($uncertainty);
            }
        }

        $lines[] = '';
        $lines[] = '## Evidence';

        if ($report->evidence === []) {
            $lines[] = '- None recorded.';
        } else {
            foreach ($report->evidence as $evidence) {
                $context = json_encode($evidence->context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $lines[] = sprintf(
                    '- `%s` (`%s`, %s confidence): %s Context: `%s`',
                    $this->inline($evidence->id),
                    $this->inline($evidence->class),
                    $this->singleLine($evidence->confidence),
                    $this->singleLine($evidence->summary),
                    $this->inline($context)
                );
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function excerpt(string $value): string
    {
        return substr($this->singleLine($value), 0, 500);
    }

    private function inline(string $value): string
    {
        return str_replace('`', '\\`', $this->singleLine($value));
    }

    private function singleLine(string $value): string
    {
        $normalized = preg_replace('/\\s+/', ' ', trim($value));

        return $normalized === null ? trim($value) : $normalized;
    }
}
