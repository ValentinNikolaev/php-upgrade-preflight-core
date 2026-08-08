<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Reporting;

use PhpUpgradePreflight\Core\Model\UpgradeReport;

final class MarkdownReportWriter
{
    public function render(UpgradeReport $report): string
    {
        return $this->renderCanonical($report->toArray());
    }

    /** @param array<string, mixed> $canonical */
    public function renderCanonical(array $canonical): string
    {
        /** @var array{schema_version:string, tool:array{name:string, version:string}} $metadata */
        $metadata = $canonical['metadata'];
        /** @var array{project_path:string, targets:list<array{package:string, constraint:string}>, from_php:?string, target_php:?string, source_paths:list<string>, frameworks:list<string>, format:string, output_path:?string} $request */
        $request = $canonical['request_summary'];
        /** @var array{path:string, platform_php:?string, root_requirements:array<string, string>|\stdClass, locked_packages:int} $project */
        $project = $canonical['project_state'];
        /** @var array{status:string, scenarios:list<array<string, mixed>>} $resolution */
        $resolution = $canonical['resolution'];

        $lines = [
            '# PHP Upgrade Preflight Report',
            '',
            sprintf(
                'Resolution: **%s** | Schema: %s | Tool: %s',
                $this->singleLine($resolution['status']),
                $this->code($metadata['schema_version']),
                $this->code($metadata['tool']['name'] . ' ' . $metadata['tool']['version'])
            ),
            '',
            '## Analysis Request',
            sprintf('- Project: %s', $this->code($request['project_path'])),
            sprintf('- Current PHP: %s', $this->code($request['from_php'] ?? 'not specified')),
            sprintf('- Target PHP: %s', $this->code($request['target_php'] ?? 'not requested')),
            sprintf('- Source paths: %s', $this->inlineList($request['source_paths'], 'default project paths')),
            sprintf('- Framework integrations: %s', $this->inlineList($request['frameworks'], 'automatic detection')),
            sprintf('- Requested format: %s', $this->code($request['format'])),
            sprintf('- Output destination: %s', $this->code($request['output_path'] ?? 'stdout')),
            '- Targets:',
        ];

        if ($request['targets'] === []) {
            $lines[] = '  - None.';
        } else {
            foreach ($request['targets'] as $target) {
                $lines[] = sprintf('  - %s: %s', $this->code($target['package']), $this->code($target['constraint']));
            }
        }

        $lines[] = '';
        $lines[] = '## Project State';
        $lines[] = sprintf('- Analyzed path: %s', $this->code($project['path']));
        $lines[] = sprintf('- Composer platform PHP: %s', $this->code($project['platform_php'] ?? 'not configured'));
        $lines[] = sprintf('- Locked packages: `%d`', $project['locked_packages']);
        $lines[] = '- Root requirements:';
        $rootRequirements = (array) $project['root_requirements'];
        if ($rootRequirements === []) {
            $lines[] = '  - None recorded.';
        } else {
            foreach ($rootRequirements as $package => $constraint) {
                $lines[] = sprintf('  - %s: %s', $this->code($package), $this->code($constraint));
            }
        }

        $lines[] = '';
        $lines[] = '## Composer Scenarios';
        if ($resolution['scenarios'] === []) {
            $lines[] = '- None executed.';
        } else {
            foreach ($resolution['scenarios'] as $scenario) {
                /** @var array{name:string, composer_version:?string, command:list<string>, duration_ms:int, exit_code:int, succeeded:bool, outcome:string, failure_type:?string, stdout_excerpt:string, stderr_excerpt:string, candidate_lock:?array{sha256:string, content_hash:?string, package_count:int}, diagnostics:list<array{package:string, constraint:string, command:list<string>, exit_code:int, stdout_excerpt:string, stderr_excerpt:string}>, temp_path:?string} $scenario */
                $lines[] = sprintf(
                    '- %s: %s (outcome %s, Composer %s, duration `%d ms`, exit `%d`, failure type %s)',
                    $this->code($scenario['name']),
                    $scenario['succeeded'] ? 'succeeded' : 'failed',
                    $this->code($scenario['outcome']),
                    $this->code($scenario['composer_version'] ?? 'unknown'),
                    $scenario['duration_ms'],
                    $scenario['exit_code'],
                    $this->code($scenario['failure_type'] ?? 'none')
                );
                $lines[] = sprintf('  - command argv: %s', $this->code($this->json($scenario['command'])));
                $lines[] = sprintf('  - temporary workspace: %s', $this->code($scenario['temp_path'] ?? 'not preserved'));
                $this->appendExcerpt($lines, 'stdout excerpt', $scenario['stdout_excerpt'], '  ');
                $this->appendExcerpt($lines, 'stderr excerpt', $scenario['stderr_excerpt'], '  ');

                if ($scenario['candidate_lock'] === null) {
                    $lines[] = '  - candidate lock: not available';
                } else {
                    $lines[] = sprintf(
                        '  - candidate lock: SHA-256 %s, content hash %s, packages `%d`',
                        $this->code($scenario['candidate_lock']['sha256']),
                        $this->code($scenario['candidate_lock']['content_hash'] ?? 'unavailable'),
                        $scenario['candidate_lock']['package_count']
                    );
                }

                if ($scenario['diagnostics'] === []) {
                    $lines[] = '  - diagnostics: none';
                } else {
                    foreach ($scenario['diagnostics'] as $diagnostic) {
                        $lines[] = sprintf(
                            '  - diagnostic for %s (exit `%d`), command argv: %s',
                            $this->code($diagnostic['package'] . ' ' . $diagnostic['constraint']),
                            $diagnostic['exit_code'],
                            $this->code($this->json($diagnostic['command']))
                        );
                        $this->appendExcerpt($lines, 'stdout excerpt', $diagnostic['stdout_excerpt'], '    ');
                        $this->appendExcerpt($lines, 'stderr excerpt', $diagnostic['stderr_excerpt'], '    ');
                    }
                }
            }
        }

        /** @var array{package_changes:list<array<string, mixed>>, root_constraint_changes:list<array<string, mixed>>} $transition */
        $transition = $canonical['transition'];
        $lines[] = '';
        $lines[] = '## Package Changes';
        if ($transition['package_changes'] === []) {
            $lines[] = '- No lockfile changes detected.';
        } else {
            foreach ($transition['package_changes'] as $change) {
                /** @var array{name:string, change_type:string, from_version:?string, to_version:?string, direct:bool, major_change:bool, package_families:list<string>, from_source_reference:?string, to_source_reference:?string, from_dist_reference:?string, to_dist_reference:?string} $change */
                $classification = $change['direct'] ? 'direct' : 'transitive';
                $majorChange = $change['major_change'] ? '; major-version jump' : '';
                $families = $change['package_families'] === []
                    ? ''
                    : '; families: ' . implode(', ', $change['package_families']);
                $lines[] = sprintf(
                    '- %s: %s %s -> %s (%s dependency%s%s)',
                    $this->code($change['name']),
                    $this->singleLine($change['change_type']),
                    $this->code($change['from_version'] ?? '-'),
                    $this->code($change['to_version'] ?? '-'),
                    $classification,
                    $majorChange,
                    $families
                );
                $lines[] = sprintf(
                    '  - source reference: %s -> %s',
                    $this->code($change['from_source_reference'] ?? '-'),
                    $this->code($change['to_source_reference'] ?? '-')
                );
                $lines[] = sprintf(
                    '  - dist reference: %s -> %s',
                    $this->code($change['from_dist_reference'] ?? '-'),
                    $this->code($change['to_dist_reference'] ?? '-')
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Root Constraint Changes';
        if ($transition['root_constraint_changes'] === []) {
            $lines[] = '- No root constraint changes are required for the requested targets.';
        } else {
            foreach ($transition['root_constraint_changes'] as $change) {
                /** @var array{package:string, change_type:string, from_constraint:?string, to_constraint:?string, reason:string, evidence:list<string>} $change */
                $lines[] = sprintf(
                    '- %s: %s %s -> %s. %s (evidence: %s)',
                    $this->code($change['package']),
                    $this->singleLine($change['change_type']),
                    $this->code($change['from_constraint'] ?? '-'),
                    $this->code($change['to_constraint'] ?? '-'),
                    $this->singleLine($change['reason']),
                    $this->references($change['evidence'])
                );
            }
        }

        /** @var list<array<string, mixed>> $blockers */
        $blockers = $canonical['blockers'];
        $lines[] = '';
        $lines[] = '## Blockers';
        if ($blockers === []) {
            $lines[] = '- None detected.';
        } else {
            foreach ($blockers as $blocker) {
                /** @var array{type:string, subject:string, requested_constraint:?string, blocker:?string, locked_version:?string, conflict:?string, dependency_path:list<string>, options:list<string>, summary:string, confidence:string, evidence:list<string>} $blocker */
                $lines[] = sprintf(
                    '- %s %s: %s (%s confidence; evidence: %s)',
                    $this->code($blocker['type']),
                    $this->code($blocker['subject']),
                    $this->singleLine($blocker['summary']),
                    $this->singleLine($blocker['confidence']),
                    $this->references($blocker['evidence'])
                );
                $lines[] = sprintf(
                    '  - requested %s; blocker %s; locked %s; conflict %s',
                    $this->code($blocker['requested_constraint'] ?? '-'),
                    $this->code($blocker['blocker'] ?? '-'),
                    $this->code($blocker['locked_version'] ?? '-'),
                    $this->code($blocker['conflict'] ?? '-')
                );
                $lines[] = sprintf(
                    '  - dependency path: %s',
                    $blocker['dependency_path'] === []
                        ? 'unknown'
                        : $this->code(implode(' -> ', $blocker['dependency_path']))
                );
                if ($blocker['options'] === []) {
                    $lines[] = '  - options: none recorded';
                } else {
                    foreach ($blocker['options'] as $option) {
                        $lines[] = '  - option: ' . $this->singleLine($option);
                    }
                }
            }
        }

        /** @var list<array{file:string, symbol:string, usage_type:string, line:?int, evidence:list<string>}> $sourceImpact */
        $sourceImpact = $canonical['source_impact'];
        $lines[] = '';
        $lines[] = '## Source Impact';
        if ($sourceImpact === []) {
            $lines[] = '- None detected.';
        } else {
            foreach ($sourceImpact as $usage) {
                $location = $usage['line'] === null ? $usage['file'] : sprintf('%s:%d', $usage['file'], $usage['line']);
                $lines[] = sprintf(
                    '- %s %s in %s (evidence: %s)',
                    $this->code($usage['usage_type']),
                    $this->code($usage['symbol']),
                    $this->code($location),
                    $this->references($usage['evidence'])
                );
            }
        }

        /** @var list<array{framework:string, severity:string, summary:string, evidence:list<string>}> $frameworkFindings */
        $frameworkFindings = $canonical['framework_findings'];
        $lines[] = '';
        $lines[] = '## Framework Findings';
        if ($frameworkFindings === []) {
            $lines[] = '- None detected.';
        } else {
            foreach ($frameworkFindings as $finding) {
                $lines[] = sprintf(
                    '- %s %s: %s (evidence: %s)',
                    $this->code($finding['framework']),
                    $this->code($finding['severity']),
                    $this->singleLine($finding['summary']),
                    $this->references($finding['evidence'])
                );
            }
        }

        /** @var array{stages:list<array{name:string, summary:string, actions:list<string>, evidence:list<string>}>} $plan */
        $plan = $canonical['plan'];
        $lines[] = '';
        $lines[] = '## Staged Plan';
        if ($plan['stages'] === []) {
            $lines[] = '- No staged actions were generated.';
        } else {
            foreach ($plan['stages'] as $index => $stage) {
                $lines[] = sprintf(
                    '%d. **%s** — %s (evidence: %s)',
                    $index + 1,
                    $this->singleLine($stage['name']),
                    $this->singleLine($stage['summary']),
                    $this->references($stage['evidence'])
                );
                if ($stage['actions'] === []) {
                    $lines[] = '   - No actions recorded.';
                } else {
                    foreach ($stage['actions'] as $action) {
                        $lines[] = '   - ' . $this->singleLine($action);
                    }
                }
            }
        }

        /** @var array{level:string, drivers:list<string>} $risk */
        $risk = $canonical['risk'];
        /** @var array{range_hours:array{0:int, 1:int}, confidence:string, components:array<string, array{0:int, 1:int}>|\stdClass, assumptions:list<string>} $effort */
        $effort = $canonical['effort'];
        $lines[] = '';
        $lines[] = '## Risk And Effort';
        $lines[] = sprintf('- Risk: %s', $this->code($risk['level']));
        $lines[] = '- Risk drivers:';
        if ($risk['drivers'] === []) {
            $lines[] = '  - None recorded.';
        } else {
            foreach ($risk['drivers'] as $driver) {
                $lines[] = '  - ' . $this->singleLine($driver);
            }
        }
        $lines[] = sprintf(
            '- Effort: `%d-%d` hours (%s confidence)',
            $effort['range_hours'][0],
            $effort['range_hours'][1],
            $this->singleLine($effort['confidence'])
        );
        $lines[] = '- Effort components:';
        $components = (array) $effort['components'];
        if ($components === []) {
            $lines[] = '  - None estimated.';
        } else {
            foreach ($components as $name => $range) {
                $lines[] = sprintf('  - %s: `%d-%d` hours', $this->code($name), $range[0], $range[1]);
            }
        }
        $lines[] = '- Effort assumptions:';
        if ($effort['assumptions'] === []) {
            $lines[] = '  - None recorded.';
        } else {
            foreach ($effort['assumptions'] as $assumption) {
                $lines[] = '  - ' . $this->singleLine($assumption);
            }
        }

        /** @var list<array{name:string, purpose:string, command:?string, priority:string}> $tests */
        $tests = $canonical['tests'];
        $lines[] = '';
        $lines[] = '## Test Guidance';
        if ($tests === []) {
            $lines[] = '- No test guidance was generated.';
        } else {
            foreach ($tests as $test) {
                $command = $test['command'] === null ? 'project-specific command required' : $this->code($test['command']);
                $lines[] = sprintf(
                    '- **%s** (%s): %s Command: %s.',
                    $this->singleLine($test['name']),
                    $this->code($test['priority']),
                    $this->singleLine($test['purpose']),
                    $command
                );
            }
        }

        /** @var list<string> $uncertainties */
        $uncertainties = $canonical['uncertainties'];
        $lines[] = '';
        $lines[] = '## Uncertainties';
        if ($uncertainties === []) {
            $lines[] = '- None recorded.';
        } else {
            foreach ($uncertainties as $uncertainty) {
                $lines[] = '- ' . $this->singleLine($uncertainty);
            }
        }

        /** @var list<array{id:string, class:string, summary:string, confidence:string, context:array<string, mixed>}> $evidence */
        $evidence = $canonical['evidence'];
        $lines[] = '';
        $lines[] = '## Evidence';
        if ($evidence === []) {
            $lines[] = '- None recorded.';
        } else {
            foreach ($evidence as $item) {
                $lines[] = sprintf(
                    '- %s (%s, %s confidence): %s Context: %s',
                    $this->code($item['id']),
                    $this->code($item['class']),
                    $this->singleLine($item['confidence']),
                    $this->singleLine($item['summary']),
                    $this->code($this->json($item['context']))
                );
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** @param list<string> $lines */
    private function appendExcerpt(array &$lines, string $label, string $value, string $indent): void
    {
        if ($value === '') {
            $lines[] = sprintf('%s- %s: *(empty)*', $indent, $label);

            return;
        }

        $lines[] = sprintf('%s- %s:', $indent, $label);
        $fence = '```';
        while (str_contains($value, $fence)) {
            $fence .= '`';
        }

        $codeIndent = $indent . '  ';
        $lines[] = $codeIndent . $fence . 'text';
        $excerptLines = preg_split('/\R/u', $value);
        if ($excerptLines === false) {
            $excerptLines = [$value];
        }
        foreach ($excerptLines as $excerptLine) {
            $lines[] = $codeIndent . $excerptLine;
        }
        $lines[] = $codeIndent . $fence;
    }

    /** @param list<string> $values */
    private function inlineList(array $values, string $empty): string
    {
        if ($values === []) {
            return $this->code($empty);
        }

        return implode(', ', array_map(fn (string $value): string => $this->code($value), $values));
    }

    /** @param list<string> $references */
    private function references(array $references): string
    {
        return implode(', ', array_map(fn (string $reference): string => $this->code($reference), $references));
    }

    /** @param mixed $value */
    private function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function code(string $value): string
    {
        $delimiter = '`';
        while (str_contains($value, $delimiter)) {
            $delimiter .= '`';
        }

        $padding = str_starts_with($value, '`')
            || str_ends_with($value, '`')
            || ($value !== trim($value) && trim($value) !== '');

        return $delimiter . ($padding ? ' ' : '') . $value . ($padding ? ' ' : '') . $delimiter;
    }

    private function singleLine(string $value): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($value));

        return $normalized === null ? trim($value) : $normalized;
    }
}
