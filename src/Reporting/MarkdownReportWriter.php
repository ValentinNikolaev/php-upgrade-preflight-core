<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Reporting;

use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Support\PathExposurePolicy;

final class MarkdownReportWriter implements ReportWriter
{
    public function render(UpgradeReport $report): string
    {
        return $this->renderCanonical($report->toArray());
    }

    /** @param array<string, mixed> $canonical */
    public function renderCanonical(array $canonical): string
    {
        $canonical = PathExposurePolicy::sanitizeCanonicalReport($canonical);

        $lines = array_merge(
            $this->renderHeadlineSection($canonical),
            $this->renderAnalysisRequestSection($canonical),
            $this->renderPlatformProvenanceSection($canonical),
            $this->renderComposerExecutionSection($canonical),
            $this->renderProjectStateSection($canonical),
            $this->renderComposerScenariosSection($canonical),
            $this->renderStagedResolutionSection($canonical),
            $this->renderPackageChangesSection($canonical),
            $this->renderFrameworkGuidanceSection($canonical),
            $this->renderRootConstraintChangesSection($canonical),
            $this->renderBlockersSection($canonical),
            $this->renderSourceInventorySection($canonical),
            $this->renderSourceImpactSection($canonical),
            $this->renderFrameworkFindingsSection($canonical),
            $this->renderPlanSection($canonical),
            $this->renderRiskAndEffortSection($canonical),
            $this->renderTestGuidanceSection($canonical),
            $this->renderUncertaintiesSection($canonical),
            $this->renderEvidenceSection($canonical)
        );

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderHeadlineSection(array $canonical): array
    {
        /** @var array{schema_version:string, tool:array{name:string, version:string}} $metadata */
        $metadata = $canonical['metadata'];
        /** @var array{status:string, scenarios:list<array<string, mixed>>} $resolution */
        $resolution = $canonical['resolution'];
        $stagedResolution = $this->optionalSection($canonical, 'staged_resolution');

        $tool = $this->code($metadata['tool']['name'] . ' ' . $metadata['tool']['version']);
        $schema = $this->code($metadata['schema_version']);

        $headline = $stagedResolution === null
            ? sprintf(
                'Resolution: **%s** | Schema: %s | Tool: %s',
                $this->singleLine($resolution['status']),
                $schema,
                $tool
            )
            : sprintf(
                'Resolution: **%s** | Staged: **%s** | Schema: %s | Tool: %s',
                $this->singleLine($resolution['status']),
                $this->singleLine($this->recordedValue($stagedResolution, 'status', 'not recorded')),
                $schema,
                $tool
            );

        return [
            '# PHP Upgrade Preflight Report',
            '',
            $headline,
        ];
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderAnalysisRequestSection(array $canonical): array
    {
        /** @var array{project_path:string, targets:list<array{package:string, constraint:string}>, from_php:?string, target_php:?string, source_paths:list<string>, frameworks:list<string>, format:string, output_path:?string, target_platform_profile?:?array{schema_version:string, completeness:string, sha256:string, provenance:string}} $request */
        $request = $canonical['request_summary'];

        $lines = [
            '',
            '## Analysis Request',
            sprintf('- Project: %s', $this->code($request['project_path'])),
            sprintf('- Current PHP: %s', $this->code($request['from_php'] ?? 'not specified')),
            sprintf('- Target PHP: %s', $this->code($request['target_php'] ?? 'not requested')),
            sprintf('- Source paths: %s', $this->inlineList($request['source_paths'], 'default project paths')),
            sprintf('- Framework integrations: %s', $this->inlineList($request['frameworks'], 'automatic detection')),
            sprintf('- Target platform profile: %s', $this->profileSummary($request['target_platform_profile'] ?? null)),
            sprintf('- Composer execution mode: %s', $this->code($request['composer_execution']['mode'] ?? 'not recorded')),
            sprintf('- Requested format: %s', $this->code($request['format'])),
            sprintf('- Output destination: %s', $this->code($request['output_path'] ?? 'stdout')),
            '- Targets:',
        ];

        if ($request['targets'] === []) {
            $lines[] = '  - None.';

            return $lines;
        }

        foreach ($request['targets'] as $target) {
            $lines[] = sprintf('  - %s: %s', $this->code($target['package']), $this->code($target['constraint']));
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderPlatformProvenanceSection(array $canonical): array
    {
        /** @var array{analyzer:array{php_version:string, provenance:string}, current_php:array{version:?string, provenance:string}, target_php:array{version:?string, provenance:string}, extensions:array{provenance:string, explicitly_modeled:bool, completeness:string, unmodeled_provenance:?string, assumptions:list<array{name:string, state:string, version:?string, provenance:string}>}, profile?:?array{schema_version:string, completeness:string, sha256:string, provenance:string, supported_classes:list<string>, closed_world:bool, toolchain_bound:list<string>, effective:list<array{name:string, class:string, state:string, version:?string, provenance:string, simulation:string}>}} $platform */
        $platform = $canonical['platform'];

        $lines = [
            '',
            '## Platform Provenance',
        ];
        $lines[] = sprintf(
            '- Analyzer PHP: %s (provenance: %s)',
            $this->code($platform['analyzer']['php_version']),
            $this->code($platform['analyzer']['provenance'])
        );
        $lines[] = sprintf(
            '- Current project PHP: %s (provenance: %s)',
            $this->code($platform['current_php']['version'] ?? 'unknown'),
            $this->code($platform['current_php']['provenance'])
        );
        $lines[] = sprintf(
            '- Target PHP: %s (provenance: %s)',
            $this->code($platform['target_php']['version'] ?? 'unknown'),
            $this->code($platform['target_php']['provenance'])
        );
        $lines[] = sprintf(
            '- Extensions: provenance %s; explicitly modeled: %s; completeness: %s; unmodeled values: %s',
            $this->code($platform['extensions']['provenance']),
            $platform['extensions']['explicitly_modeled'] ? 'yes' : 'no',
            $this->code($platform['extensions']['completeness']),
            $this->code($platform['extensions']['unmodeled_provenance'] ?? 'none')
        );
        foreach ($platform['extensions']['assumptions'] as $assumption) {
            $lines[] = sprintf(
                '  - %s: %s%s (provenance: %s)',
                $this->code($assumption['name']),
                $this->code($assumption['state']),
                $assumption['version'] === null ? '' : ' at ' . $this->code($assumption['version']),
                $this->code($assumption['provenance'])
            );
        }

        $profile = $platform['profile'] ?? null;
        if ($profile === null) {
            $lines[] = '- Target platform profile: none; platform packages not explicitly modeled above remain analyzer-host dependent.';

            return $lines;
        }

        $lines[] = sprintf(
            '- Target platform profile: schema %s; completeness %s; SHA-256 %s; provenance %s',
            $this->code($profile['schema_version']),
            $this->code($profile['completeness']),
            $this->code($profile['sha256']),
            $this->code($profile['provenance'])
        );
        $lines[] = $profile['closed_world']
            ? '  - Coverage guarantee: complete closed-world modeling; every unlisted safely simulated platform package is modeled absent. Toolchain-bound values remain tied to the Composer executable.'
            : '  - Coverage guarantee: partial and host-dependent; unlisted platform packages may come from the analyzer runtime.';
        $lines[] = sprintf('  - Supported classes: %s', $this->inlineList($profile['supported_classes'], 'none'));
        $lines[] = sprintf('  - Toolchain-bound packages: %s', $this->inlineList($profile['toolchain_bound'], 'none'));
        $lines[] = '  - Effective platform decisions:';
        if ($profile['effective'] === []) {
            $lines[] = '    - None.';

            return $lines;
        }

        foreach ($profile['effective'] as $decision) {
            $lines[] = sprintf(
                '    - %s (%s): %s%s; provenance %s; simulation %s',
                $this->code($decision['name']),
                $this->code($decision['class']),
                $this->code($decision['state']),
                $decision['version'] === null ? '' : ' at ' . $this->code($decision['version']),
                $this->code($decision['provenance']),
                $this->code($decision['simulation'])
            );
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderComposerExecutionSection(array $canonical): array
    {
        $lines = [
            '',
            '## Composer Execution Provenance',
        ];

        $composerExecution = $this->optionalSection($canonical, 'composer_execution');
        if ($composerExecution === null) {
            $lines[] = '- Not recorded by this report schema.';

            return $lines;
        }

        $lines[] = sprintf(
            '- Mode: %s; Composer version: %s; expected: %s; matches: %s',
            $this->code($this->recordedValue($composerExecution, 'mode', 'unknown')),
            $this->code($this->recordedValue($composerExecution, 'composer_version', 'unknown')),
            $this->code($this->recordedValue($composerExecution, 'expected_version', 'unknown')),
            $this->code($this->yesNoLabel($composerExecution, 'version_matches_expectation'))
        );
        $lines[] = sprintf(
            '- Executable selection: %s; environment: %s; network: %s; repositories: %s',
            $this->code($this->recordedValue($composerExecution, 'executable_selection', 'unknown')),
            $this->code($this->recordedValue($composerExecution, 'environment_mode', 'unknown')),
            $this->code($this->recordedValue($composerExecution, 'network_policy', 'unknown')),
            $this->code($this->recordedValue($composerExecution, 'repository_source_mode', 'unknown'))
        );
        $lines[] = sprintf(
            '- Timeouts: scenario %s; diagnostic %s; Composer home: %s',
            $this->code($this->measurementLabel($composerExecution, 'scenario_timeout_seconds', 's')),
            $this->code($this->measurementLabel($composerExecution, 'diagnostic_timeout_seconds', 's')),
            $this->code($this->recordedValue($composerExecution, 'composer_home', 'unknown'))
        );
        $lines[] = sprintf(
            '- Inheritance: global configuration %s; credentials may be inherited %s; offline requested %s; process/OS isolation %s',
            $this->yesNoLabel($composerExecution, 'global_configuration_inherited'),
            $this->yesNoLabel($composerExecution, 'credentials_may_be_inherited'),
            $this->yesNoLabel($composerExecution, 'offline_requested'),
            $this->yesNoLabel($composerExecution, 'process_os_isolation')
        );
        $lines[] = sprintf(
            '- Side effects: scripts %s; plugins %s; installation %s; audit %s; interaction %s; progress %s.',
            $this->enabledLabel($composerExecution['scripts_enabled'] ?? null),
            $this->enabledLabel($composerExecution['plugins_enabled'] ?? null),
            $this->enabledLabel($composerExecution['installation_enabled'] ?? null),
            $this->enabledLabel($composerExecution['audit_enabled'] ?? null),
            $this->enabledLabel($composerExecution['interaction_enabled'] ?? null),
            $this->enabledLabel($composerExecution['progress_enabled'] ?? null)
        );

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderProjectStateSection(array $canonical): array
    {
        /** @var array{path:string, platform_php:?string, root_requirements:array<string, string>|\stdClass, locked_packages:int} $project */
        $project = $canonical['project_state'];

        $lines = [
            '',
            '## Project State',
            sprintf('- Analyzed path: %s', $this->code($project['path'])),
            sprintf('- Composer platform PHP: %s', $this->code($project['platform_php'] ?? 'not configured')),
            sprintf('- Locked packages: `%d`', $project['locked_packages']),
            '- Root requirements:',
        ];

        $rootRequirements = (array) $project['root_requirements'];
        if ($rootRequirements === []) {
            $lines[] = '  - None recorded.';

            return $lines;
        }

        foreach ($rootRequirements as $package => $constraint) {
            $lines[] = sprintf('  - %s: %s', $this->code((string) $package), $this->code($constraint));
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderComposerScenariosSection(array $canonical): array
    {
        /** @var array{status:string, scenarios:list<array<string, mixed>>} $resolution */
        $resolution = $canonical['resolution'];

        $lines = [
            '',
            '## Composer Scenarios',
        ];
        if ($resolution['scenarios'] === []) {
            $lines[] = '- None executed.';

            return $lines;
        }

        foreach ($resolution['scenarios'] as $scenario) {
            /** @var array{name:string, composer_version:?string, command:list<string>, duration_ms:int, exit_code:int, succeeded:bool, outcome:string, failure_type:?string, stdout_excerpt:string, stderr_excerpt:string, candidate_lock:?array{sha256:string, content_hash:?string, package_count:int}, diagnostics:list<array{package:string, constraint:string, command:list<string>, exit_code:int, outcome:string, stdout_excerpt:string, stderr_excerpt:string}>, temp_path:?string} $scenario */
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

                continue;
            }

            foreach ($scenario['diagnostics'] as $diagnostic) {
                // A non-zero `composer prohibits` exit is ordinary evidence, so the outcome, not the
                // exit code, separates a usable probe from one that timed out or never ran. Documents
                // written before schema 0.8 carry no outcome and are reported as unrecorded.
                $lines[] = sprintf(
                    '  - diagnostic for %s (outcome %s, exit `%d`), command argv: %s',
                    $this->code($diagnostic['package'] . ' ' . $diagnostic['constraint']),
                    $this->code($this->recordedValue($diagnostic, 'outcome', 'unknown')),
                    $diagnostic['exit_code'],
                    $this->code($this->json($diagnostic['command']))
                );
                $this->appendExcerpt($lines, 'stdout excerpt', $diagnostic['stdout_excerpt'], '    ');
                $this->appendExcerpt($lines, 'stderr excerpt', $diagnostic['stderr_excerpt'], '    ');
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderStagedResolutionSection(array $canonical): array
    {
        $lines = [
            '',
            '## Staged Composer Resolution',
        ];

        $stagedResolution = $this->optionalSection($canonical, 'staged_resolution');
        if ($stagedResolution === null) {
            $lines[] = '- Not recorded by this report schema.';

            return $lines;
        }

        $lines[] = sprintf(
            '- Execution: %s; status: %s; provider: %s; stop reason: %s',
            $this->code($this->recordedValue($stagedResolution, 'execution_state', 'not recorded')),
            $this->code($this->recordedValue($stagedResolution, 'status', 'not recorded')),
            $this->code($this->recordedValue($stagedResolution, 'provider', 'none')),
            $this->code($this->recordedValue($stagedResolution, 'stop_reason', 'none'))
        );

        $stages = $this->optionalList($stagedResolution, 'stages');
        if ($stages === []) {
            $lines[] = '- No framework stages were executed.';
        } else {
            foreach ($stages as $stage) {
                $lines = array_merge($lines, $this->renderStage($stage));
            }
        }

        $lines[] = '- Staged source-impact registry:';
        $stagedSourceImpact = $this->optionalList($stagedResolution, 'source_impact');
        if ($stagedSourceImpact === []) {
            $lines[] = '  - None recorded.';
        } else {
            foreach ($stagedSourceImpact as $finding) {
                $lines[] = sprintf(
                    '  - %s stages %s: %s impact for %s (evidence: %s)',
                    $this->code($this->recordedValue($finding, 'id', 'not recorded')),
                    $this->inlineList($finding['stage_ids'] ?? [], 'none'),
                    $this->code((string) $finding['severity']),
                    $this->code($finding['affected_package'] ?? 'package unknown'),
                    $this->references($finding['evidence'])
                );
                $lines = array_merge($lines, $this->renderOccurrences($finding['occurrences'] ?? [], '    '));
            }
        }

        $lines[] = '- Blocker registry:';
        $blockerRegistry = $this->optionalList($stagedResolution, 'blocker_registry');
        if ($blockerRegistry === []) {
            $lines[] = '  - None recorded.';

            return $lines;
        }

        foreach ($blockerRegistry as $blocker) {
            $history = array_map(
                static fn (array $event): string => sprintf('%s@%d', $event['status'], $event['attempt']),
                $blocker['lifecycle_history']
            );
            $lines[] = sprintf(
                '  - %s stage %s: %s %s; lifecycle %s (%s); blocking package %s; constraint %s; path %s',
                $this->code((string) $blocker['id']),
                $this->code((string) $blocker['stage_id']),
                $this->code((string) $blocker['category']),
                $this->code((string) $blocker['subject']),
                $this->code((string) $blocker['lifecycle']),
                implode(' -> ', $history),
                $this->code($blocker['blocking_package'] ?? '-'),
                $this->code($blocker['constraint'] ?? '-'),
                $blocker['dependency_path'] === []
                    ? 'unknown'
                    : $this->code(implode(' -> ', $blocker['dependency_path']))
            );
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $stage
     * @return list<string>
     */
    private function renderStage(array $stage): array
    {
        $lines = [];
        $lines[] = sprintf(
            '- **%s** (%s -> %s): execution %s; resolution %s; selected attempt %s',
            $this->singleLine((string) $stage['id']),
            $this->code((string) $stage['from_major']),
            $this->code((string) $stage['to_major']),
            $this->code((string) $stage['execution_state']),
            $this->code($stage['resolution_status'] ?? 'not evaluated'),
            $this->code($stage['selected_attempt'] === null ? 'none' : (string) $stage['selected_attempt'])
        );
        $lines[] = sprintf('  - analysis PHP: %s; source snapshot: %s', $this->code((string) $stage['analysis_php']), $this->code((string) $stage['source_snapshot']));
        $lines[] = isset($stage['source_snapshot_note'])
            ? '  - ' . $this->singleLine((string) $stage['source_snapshot_note'])
            : '  - Source snapshot note: not recorded.';
        $lines[] = sprintf(
            '  - effective platform: %s; completeness %s; profile %s',
            $this->code($stage['platform']['effective_sha256'] ?? 'not recorded'),
            $this->code($stage['platform']['completeness'] ?? 'not recorded'),
            $this->code($stage['platform']['profile_sha256'] ?? 'none')
        );
        $lines[] = sprintf(
            '  - Composer policy: %s; mode %s; stage duration %s',
            $this->code($stage['composer_execution']['effective_sha256'] ?? 'not recorded'),
            $this->code($stage['composer_execution']['configuration']['mode'] ?? 'not recorded'),
            $this->code($this->measurementLabel($stage, 'duration_ms', 'ms'))
        );
        $lines[] = sprintf(
            '  - stage evidence: %s',
            $this->inlineList($stage['evidence'] ?? [], 'none')
        );
        $lines[] = sprintf(
            '  - state chain: predecessor %s; input %s; output %s',
            $this->code($stage['predecessor_state']['state_sha256'] ?? 'none'),
            $this->code($stage['input_state']['state_sha256'] ?? 'none'),
            $this->code($stage['output_state']['state_sha256'] ?? 'none')
        );
        foreach ($stage['attempts'] as $attempt) {
            /** @var array<string, mixed> $attemptScenario */
            $attemptScenario = (array) ($attempt['scenario'] ?? []);
            $lines[] = sprintf(
                '  - attempt `%d` %s: outcome %s; duration %s; selected %s; blockers %s',
                $attempt['number'],
                $this->code((string) $attempt['strategy']),
                $this->code($this->recordedValue($attemptScenario, 'outcome', 'unknown')),
                $this->code($this->measurementLabel($attemptScenario, 'duration_ms', 'ms')),
                $attempt['selected'] ? 'yes' : 'no',
                $this->inlineList($attempt['blocker_ids'], 'none')
            );
            foreach ($attempt['root_constraint_changes'] as $change) {
                $lines[] = sprintf(
                    '    - analyzer-only root change %s: %s -> %s',
                    $this->code((string) $change['package']),
                    $this->code($change['from_constraint'] ?? '-'),
                    $this->code($change['to_constraint'] ?? '-')
                );
            }
        }
        foreach ($stage['package_changes'] as $change) {
            $lines[] = sprintf(
                '  - selected package change %s: %s -> %s',
                $this->code((string) $change['name']),
                $this->code($change['from_version'] ?? '-'),
                $this->code($change['to_version'] ?? '-')
            );
        }
        foreach ($stage['source_findings'] as $finding) {
            $lines[] = sprintf(
                '  - original-source finding (%s): %s',
                $this->code((string) $finding['severity']),
                $this->singleLine((string) $finding['summary'])
            );
        }
        $lines[] = sprintf('  - blocker references: %s', $this->inlineList($stage['blockers'] ?? [], 'none'));
        $lines[] = sprintf('  - source-impact references: %s', $this->inlineList($stage['source_impact'] ?? [], 'none'));
        if (isset($stage['risk']['level'])) {
            $lines[] = sprintf(
                '  - risk for %s: %s',
                $this->code((string) ($stage['risk']['stage_id'] ?? 'not recorded')),
                $this->code((string) $stage['risk']['level'])
            );
        }
        if (isset($stage['effort']['range_hours'])) {
            $lines[] = sprintf(
                '  - effort: %d-%d hours (%s confidence)',
                $stage['effort']['range_hours'][0],
                $stage['effort']['range_hours'][1],
                $this->code((string) ($stage['effort']['confidence'] ?? 'not recorded'))
            );
        }
        foreach (($stage['recommended_actions'] ?? []) as $action) {
            $lines[] = '  - action: ' . $this->singleLine((string) $action);
        }
        foreach (($stage['tests'] ?? []) as $test) {
            $lines[] = sprintf(
                '  - test for %s: %s (%s)',
                $this->code((string) $test['stage_id']),
                $this->singleLine((string) $test['purpose']),
                $this->code((string) $test['priority'])
            );
        }
        if ($stage['stop_reason'] !== null) {
            $lines[] = sprintf('  - stop reason: %s', $this->code((string) $stage['stop_reason']));
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderPackageChangesSection(array $canonical): array
    {
        /** @var array{package_changes:list<array<string, mixed>>, root_constraint_changes:list<array<string, mixed>>, framework_guidance:list<array<string, mixed>>} $transition */
        $transition = $canonical['transition'];

        $lines = [
            '',
            '## Package Changes',
        ];
        if ($transition['package_changes'] === []) {
            $lines[] = '- No lockfile changes detected.';

            return $lines;
        }

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

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderFrameworkGuidanceSection(array $canonical): array
    {
        /** @var array{package_changes:list<array<string, mixed>>, root_constraint_changes:list<array<string, mixed>>, framework_guidance:list<array<string, mixed>>} $transition */
        $transition = $canonical['transition'];

        $lines = [
            '',
            '## Framework Transition Guidance',
        ];
        if ($transition['framework_guidance'] === []) {
            $lines[] = '- No framework transition assessment was produced.';

            return $lines;
        }

        foreach ($transition['framework_guidance'] as $guidance) {
            $lines[] = sprintf(
                '- %s: %s (%s -> %s; evidence: %s)',
                $this->code((string) $guidance['framework']),
                $this->code((string) $guidance['status']),
                $this->code($guidance['source_major'] === null ? 'unknown' : (string) $guidance['source_major']),
                $this->code($guidance['target_major'] === null ? 'unknown' : (string) $guidance['target_major']),
                $this->references($guidance['evidence'])
            );
            foreach ($guidance['hops'] as $hop) {
                $lines[] = sprintf(
                    '  - hop %s -> %s: %s; rule pack %s (evidence: %s)',
                    $this->code((string) $hop['from_major']),
                    $this->code((string) $hop['to_major']),
                    $this->code($hop['status']),
                    $this->code($hop['rule_pack'] ?? 'none'),
                    $this->references($hop['evidence'])
                );
            }
            foreach ($guidance['uncertainties'] as $uncertainty) {
                $lines[] = '  - uncertainty: ' . $this->singleLine($uncertainty);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderRootConstraintChangesSection(array $canonical): array
    {
        /** @var array{package_changes:list<array<string, mixed>>, root_constraint_changes:list<array<string, mixed>>, framework_guidance:list<array<string, mixed>>} $transition */
        $transition = $canonical['transition'];

        $lines = [
            '',
            '## Root Constraint Changes',
        ];
        if ($transition['root_constraint_changes'] === []) {
            $lines[] = '- No root constraint changes are required for the requested targets.';

            return $lines;
        }

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

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderBlockersSection(array $canonical): array
    {
        /** @var list<array<string, mixed>> $blockers */
        $blockers = $canonical['blockers'];

        $lines = [
            '',
            '## Blockers',
        ];
        if ($blockers === []) {
            $lines[] = '- None detected.';

            return $lines;
        }

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

                continue;
            }

            foreach ($blocker['options'] as $option) {
                $lines[] = '  - option: ' . $this->singleLine($option);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderSourceInventorySection(array $canonical): array
    {
        /** @var list<array{file:string, symbol:string, usage_type:string, line:?int, evidence:list<string>}> $sourceInventory */
        $sourceInventory = $canonical['source_inventory'];

        $lines = [
            '',
            '## Source Inventory',
        ];
        if ($sourceInventory === []) {
            $lines[] = '- None detected.';

            return $lines;
        }

        foreach ($sourceInventory as $usage) {
            $location = $usage['line'] === null ? $usage['file'] : sprintf('%s:%d', $usage['file'], $usage['line']);
            $lines[] = sprintf(
                '- %s %s in %s (evidence: %s)',
                $this->code($usage['usage_type']),
                $this->code($usage['symbol']),
                $this->code($location),
                $this->references($usage['evidence'])
            );
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderSourceImpactSection(array $canonical): array
    {
        /** @var list<array{id?:?string, stage_ids?:list<string>, affected_package:?string, ownership:string, relevance:string, reason:string, severity:string, occurrences:list<array{file:string, symbol:string, usage_type:string, line:?int, evidence:list<string>}>, evidence:list<string>}> $sourceImpact */
        $sourceImpact = $canonical['source_impact'];

        $lines = [
            '',
            '## Actionable Source Impact',
        ];
        if ($sourceImpact === []) {
            $lines[] = '- None detected.';

            return $lines;
        }

        foreach ($sourceImpact as $finding) {
            $lines[] = sprintf(
                '- %s %s impact for %s (%s ownership; %s; stage references: %s): %s (evidence: %s)',
                $this->code($this->recordedValue($finding, 'id', 'not recorded')),
                $this->code($finding['severity']),
                $this->code($finding['affected_package'] ?? 'package unknown'),
                $this->code($finding['ownership']),
                $this->code($finding['relevance']),
                $this->inlineList($finding['stage_ids'] ?? [], 'direct-final only'),
                $this->singleLine($finding['reason']),
                $this->references($finding['evidence'])
            );
            $lines = array_merge($lines, $this->renderOccurrences($finding['occurrences'], '  '));
        }

        return $lines;
    }

    /**
     * @param list<array{file:string, symbol:string, usage_type:string, line:?int, evidence:list<string>}> $occurrences
     * @return list<string>
     */
    private function renderOccurrences(array $occurrences, string $indent): array
    {
        $lines = [];
        foreach ($occurrences as $usage) {
            $location = $usage['line'] === null ? $usage['file'] : sprintf('%s:%d', $usage['file'], $usage['line']);
            $lines[] = sprintf(
                '%s- %s %s in %s (evidence: %s)',
                $indent,
                $this->code($usage['usage_type']),
                $this->code($usage['symbol']),
                $this->code($location),
                $this->references($usage['evidence'])
            );
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderFrameworkFindingsSection(array $canonical): array
    {
        /** @var list<array{framework:string, severity:string, summary:string, applies_to_hops:list<array{from_major:int, to_major:int}>, evidence:list<string>}> $frameworkFindings */
        $frameworkFindings = $canonical['framework_findings'];

        $lines = [
            '',
            '## Framework Findings',
        ];
        if ($frameworkFindings === []) {
            $lines[] = '- None detected.';

            return $lines;
        }

        foreach ($frameworkFindings as $finding) {
            $lines[] = sprintf(
                '- %s %s: %s (evidence: %s)',
                $this->code($finding['framework']),
                $this->code($finding['severity']),
                $this->singleLine($finding['summary']),
                $this->references($finding['evidence'])
            );
            if ($finding['applies_to_hops'] !== []) {
                $lines[] = '  - applies to hops: ' . implode(', ', array_map(
                    fn (array $hop): string => $this->code($hop['from_major'] . ' -> ' . $hop['to_major']),
                    $finding['applies_to_hops']
                ));
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderPlanSection(array $canonical): array
    {
        /** @var array{stages:list<array{stage_id:?string, name:string, summary:string, actions:list<string>, evidence:list<string>}>} $plan */
        $plan = $canonical['plan'];

        $lines = [
            '',
            '## Staged Plan',
        ];
        if ($plan['stages'] === []) {
            $lines[] = '- No staged actions were generated.';

            return $lines;
        }

        foreach ($plan['stages'] as $index => $stage) {
            $lines[] = sprintf(
                '%d. **%s** — %s; executed stage %s (evidence: %s)',
                $index + 1,
                $this->singleLine($stage['name']),
                $this->singleLine($stage['summary']),
                $this->code($stage['stage_id'] ?? 'not recorded'),
                $this->references($stage['evidence'])
            );
            if ($stage['actions'] === []) {
                $lines[] = '   - No actions recorded.';

                continue;
            }

            foreach ($stage['actions'] as $action) {
                $lines[] = '   - ' . $this->singleLine($action);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderRiskAndEffortSection(array $canonical): array
    {
        /** @var array{level:string, drivers:list<string>} $risk */
        $risk = $canonical['risk'];
        /** @var array{range_hours:array{0:int, 1:int}, confidence:string, components:array<string, array{0:int, 1:int}>|\stdClass, assumptions:list<string>} $effort */
        $effort = $canonical['effort'];

        $lines = [
            '',
            '## Risk And Effort',
            sprintf('- Risk: %s', $this->code($risk['level'])),
            '- Risk drivers:',
        ];
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
                $lines[] = sprintf('  - %s: `%d-%d` hours', $this->code((string) $name), $range[0], $range[1]);
            }
        }
        $lines[] = '- Effort assumptions:';
        if ($effort['assumptions'] === []) {
            $lines[] = '  - None recorded.';

            return $lines;
        }

        foreach ($effort['assumptions'] as $assumption) {
            $lines[] = '  - ' . $this->singleLine($assumption);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderTestGuidanceSection(array $canonical): array
    {
        /** @var list<array{name:string, purpose:string, command:?string, priority:string}> $tests */
        $tests = $canonical['tests'];

        $lines = [
            '',
            '## Test Guidance',
        ];
        if ($tests === []) {
            $lines[] = '- No test guidance was generated.';

            return $lines;
        }

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

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderUncertaintiesSection(array $canonical): array
    {
        /** @var list<string> $uncertainties */
        $uncertainties = $canonical['uncertainties'];

        $lines = [
            '',
            '## Uncertainties',
        ];
        if ($uncertainties === []) {
            $lines[] = '- None recorded.';

            return $lines;
        }

        foreach ($uncertainties as $uncertainty) {
            $lines[] = '- ' . $this->singleLine($uncertainty);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    private function renderEvidenceSection(array $canonical): array
    {
        /** @var list<array{id:string, class:string, summary:string, confidence:string, context:array<string, mixed>}> $evidence */
        $evidence = $canonical['evidence'];

        $lines = [
            '',
            '## Evidence',
        ];
        if ($evidence === []) {
            $lines[] = '- None recorded.';

            return $lines;
        }

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

        return $lines;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return ?array<string, mixed>
     */
    private function optionalSection(array $canonical, string $key): ?array
    {
        if (!isset($canonical[$key]) || !is_array($canonical[$key])) {
            return null;
        }

        /** @var array<string, mixed> $section */
        $section = $canonical[$key];

        return $section;
    }

    /**
     * @param array<string, mixed> $section
     * @return list<array<string, mixed>>
     */
    private function optionalList(array $section, string $key): array
    {
        if (!isset($section[$key]) || !is_array($section[$key])) {
            return [];
        }

        /** @var list<array<string, mixed>> $values */
        $values = array_values($section[$key]);

        return $values;
    }

    /**
     * Projects a recorded scalar. An absent key is reported as unrecorded rather
     * than replaced with an invented value; a recorded null uses $nullLabel.
     *
     * @param array<string, mixed> $values
     */
    private function recordedValue(array $values, string $key, string $nullLabel): string
    {
        if (!array_key_exists($key, $values)) {
            return 'not recorded';
        }

        $value = $values[$key];

        return $value === null ? $nullLabel : (string) $value;
    }

    /** @param array<string, mixed> $values */
    private function yesNoLabel(array $values, string $key, string $nullLabel = 'unknown'): string
    {
        if (!array_key_exists($key, $values)) {
            return 'not recorded';
        }

        $value = $values[$key];
        if ($value === null) {
            return $nullLabel;
        }

        return $value ? 'yes' : 'no';
    }

    /** @param mixed $value */
    private function enabledLabel($value): string
    {
        if (!is_bool($value)) {
            return 'not recorded';
        }

        return $value ? 'enabled' : 'disabled';
    }

    /** @param array<string, mixed> $values */
    private function measurementLabel(array $values, string $key, string $unit): string
    {
        if (!isset($values[$key]) || !is_numeric($values[$key])) {
            return 'not recorded';
        }

        return sprintf('%d %s', (int) $values[$key], $unit);
    }

    /** @param ?array{schema_version:string, completeness:string, sha256:string, provenance:string} $profile */
    private function profileSummary(?array $profile): string
    {
        if ($profile === null) {
            return $this->code('not supplied');
        }

        return sprintf(
            'schema %s, completeness %s, SHA-256 %s, provenance %s',
            $this->code($profile['schema_version']),
            $this->code($profile['completeness']),
            $this->code($profile['sha256']),
            $this->code($profile['provenance'])
        );
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
            $lines[] = $excerptLine === '' ? '' : $codeIndent . $excerptLine;
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
