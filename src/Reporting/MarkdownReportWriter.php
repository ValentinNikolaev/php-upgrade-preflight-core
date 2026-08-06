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

        if ($report->scenarios() === []) {
            $lines[] = '- None executed.';
        } else {
            foreach ($report->scenarios() as $scenario) {
                $lines[] = sprintf(
                    '- `%s`: %s (Composer `%s`, duration `%d ms`, exit `%d`, failure type `%s`)',
                    $this->inline($scenario->scenario()->name()),
                    $scenario->succeeded() ? 'succeeded' : 'failed',
                    $this->inline($scenario->composerVersion() ?? 'unknown'),
                    $scenario->durationMs(),
                    $scenario->exitCode(),
                    $scenario->failureType() ?? 'none'
                );

                $command = json_encode($scenario->command(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $lines[] = sprintf('  - command argv: `%s`', $this->inline($command));

                if (trim($scenario->stdout()) !== '') {
                    $lines[] = sprintf('  - stdout excerpt: `%s`', $this->inline($this->excerpt($scenario->stdout())));
                }
                if (trim($scenario->stderr()) !== '') {
                    $lines[] = sprintf('  - stderr excerpt: `%s`', $this->inline($this->excerpt($scenario->stderr())));
                }

                $candidateLock = $scenario->candidateLockEvidence();
                if ($candidateLock !== null) {
                    $lines[] = sprintf(
                        '  - candidate lock: SHA-256 `%s`, content hash `%s`, packages `%d`',
                        $candidateLock->sha256(),
                        $this->inline($candidateLock->contentHash() ?? 'unavailable'),
                        $candidateLock->packageCount()
                    );
                }

                foreach ($scenario->diagnostics() as $diagnostic) {
                    $diagnosticCommand = json_encode($diagnostic->command(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $lines[] = sprintf(
                        '  - diagnostic for `%s %s` (exit `%d`), command argv: `%s`',
                        $this->inline($diagnostic->package()),
                        $this->inline($diagnostic->constraint()),
                        $diagnostic->exitCode(),
                        $this->inline($diagnosticCommand)
                    );
                    if (trim($diagnostic->stdout()) !== '') {
                        $lines[] = sprintf('    - stdout excerpt: `%s`', $this->inline($this->excerpt($diagnostic->stdout())));
                    }
                    if (trim($diagnostic->stderr()) !== '') {
                        $lines[] = sprintf('    - stderr excerpt: `%s`', $this->inline($this->excerpt($diagnostic->stderr())));
                    }
                }
            }
        }

        $lines[] = '';
        $lines[] = '## Blockers';

        if ($report->blockers() === []) {
            $lines[] = '- None detected.';
        } else {
            foreach ($report->blockers() as $blocker) {
                $lines[] = sprintf('- `%s` %s (%s)', $blocker->type(), $blocker->summary(), implode(', ', $blocker->evidence()));
            }
        }

        $lines[] = '';
        $lines[] = '## Package Changes';

        if ($report->lockDiff()->packageChanges() === []) {
            $lines[] = '- No lockfile changes detected.';
        } else {
            foreach ($report->lockDiff()->packageChanges() as $change) {
                $lines[] = sprintf('- `%s`: %s `%s` -> `%s`', $change->name(), $change->changeType(), $change->fromVersion() ?? '-', $change->toVersion() ?? '-');
            }
        }

        $lines[] = '';
        $lines[] = '## Root Constraint Changes';

        if ($report->rootConstraintChanges() === []) {
            $lines[] = '- No root constraint changes are required for the requested targets.';
        } else {
            foreach ($report->rootConstraintChanges() as $change) {
                $lines[] = sprintf(
                    '- `%s`: %s `%s` -> `%s`. %s (%s)',
                    $this->inline($change->package()),
                    $change->changeType(),
                    $this->inline($change->fromConstraint() ?? '-'),
                    $this->inline($change->toConstraint() ?? '-'),
                    $this->singleLine($change->reason()),
                    implode(', ', $change->evidence())
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Source Impact';

        if ($report->sourceImpact() === []) {
            $lines[] = '- None detected.';
        } else {
            foreach ($report->sourceImpact() as $usage) {
                $location = $usage->line() === null ? $usage->file() : sprintf('%s:%d', $usage->file(), $usage->line());
                $lines[] = sprintf(
                    '- `%s` `%s` in `%s` (%s)',
                    $this->inline($usage->usageType()),
                    $this->inline($usage->symbol()),
                    $this->inline($location),
                    implode(', ', $usage->evidence())
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Framework Findings';

        if ($report->frameworkFindings() === []) {
            $lines[] = '- None detected.';
        } else {
            foreach ($report->frameworkFindings() as $finding) {
                $lines[] = sprintf('- `%s` %s (%s)', $finding->severity(), $finding->summary(), implode(', ', $finding->evidence()));
            }
        }

        $lines[] = '';
        $lines[] = '## Staged Plan';

        if ($report->planStages() === []) {
            $lines[] = '- No staged actions were generated.';
        } else {
            foreach ($report->planStages() as $index => $stage) {
                $lines[] = sprintf(
                    '%d. **%s** — %s (%s)',
                    $index + 1,
                    $this->singleLine($stage->name()),
                    $this->singleLine($stage->summary()),
                    implode(', ', $stage->evidence())
                );
                foreach ($stage->actions() as $action) {
                    $lines[] = '   - ' . $this->singleLine($action);
                }
            }
        }

        $lines[] = '';
        $lines[] = '## Risk And Effort';
        $lines[] = sprintf('- Risk: `%s`', $report->risk()->level());
        $rangeHours = $report->effort()->rangeHours();
        $lines[] = sprintf('- Effort: `%d-%d` hours (%s confidence)', $rangeHours[0], $rangeHours[1], $report->effort()->confidence());

        $lines[] = '';
        $lines[] = '## Test Guidance';

        if ($report->tests() === []) {
            $lines[] = '- No test guidance was generated.';
        } else {
            foreach ($report->tests() as $test) {
                $command = $test->command() === null ? 'project-specific command required' : sprintf('`%s`', $this->inline($test->command()));
                $lines[] = sprintf(
                    '- **%s** (`%s`): %s Command: %s.',
                    $this->singleLine($test->name()),
                    $this->inline($test->priority()),
                    $this->singleLine($test->purpose()),
                    $command
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Uncertainties';

        if ($report->uncertainties() === []) {
            $lines[] = '- None recorded.';
        } else {
            foreach ($report->uncertainties() as $uncertainty) {
                $lines[] = '- ' . $this->singleLine($uncertainty);
            }
        }

        $lines[] = '';
        $lines[] = '## Evidence';

        if ($report->evidence() === []) {
            $lines[] = '- None recorded.';
        } else {
            foreach ($report->evidence() as $evidence) {
                $context = json_encode($evidence->context(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $lines[] = sprintf(
                    '- `%s` (`%s`, %s confidence): %s Context: `%s`',
                    $this->inline($evidence->id()),
                    $this->inline($evidence->evidenceClass()),
                    $this->singleLine($evidence->confidence()),
                    $this->singleLine($evidence->summary()),
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
