<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Reporting;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\FrameworkGuidance;
use PhpUpgradePreflight\Core\Model\FrameworkHop;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\PlanStage;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\RootConstraintChange;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\TestGuidance;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Reporting\MarkdownReportWriter;
use PHPUnit\Framework\TestCase;

final class MarkdownReportWriterTest extends TestCase
{
    public function testItProjectsDiagnosticsSourceImpactUncertaintiesAndEvidence(): void
    {
        $longStdoutLine = $this->longStdoutLine();
        $report = $this->buildReport();

        $writer = new MarkdownReportWriter();
        $markdown = $writer->render($report);

        self::assertSame($markdown, $writer->renderCanonical($report->toArray()));

        self::assertStringContainsString('## Composer Scenarios', $markdown);
        self::assertStringContainsString('Schema: `0.8`', $markdown);
        self::assertStringContainsString('Tool: `php-upgrade-preflight 0.3.0`', $markdown);
        self::assertStringContainsString('## Staged Composer Resolution', $markdown);
        self::assertStringContainsString('## Analysis Request', $markdown);
        self::assertStringContainsString('Current PHP: `7.4`', $markdown);
        self::assertStringContainsString('Target PHP: `8.2.0`', $markdown);
        self::assertStringContainsString('Source paths: `packages/core/src`', $markdown);
        self::assertStringContainsString('Framework integrations: `laravel`', $markdown);
        self::assertStringContainsString('Requested format: `markdown`', $markdown);
        self::assertStringContainsString('Project: `[PROJECT_ROOT]`', $markdown);
        self::assertStringContainsString('Output destination: `[REPORT_OUTPUT]`', $markdown);
        self::assertStringContainsString('## Project State', $markdown);
        self::assertStringContainsString('Composer platform PHP: `7.4.33`', $markdown);
        self::assertStringContainsString('completeness: `none`', $markdown);
        self::assertStringContainsString('`fixture/dependency`: `^1.0`', $markdown);
        self::assertStringContainsString('Composer executable unavailable.', $markdown);
        self::assertStringContainsString($longStdoutLine, $markdown);
        self::assertStringContainsString('```embedded fence```', $markdown);
        self::assertStringContainsString('after-fence', $markdown);
        self::assertStringContainsString('    ````text', $markdown);
        self::assertDoesNotMatchRegularExpression('/[ \t]+$/m', $markdown);
        self::assertStringContainsString('temporary workspace: `C:\\temp\\php-upgrade-preflight-debug`', $markdown);
        self::assertStringContainsString('outcome `process_failure`', $markdown);
        self::assertStringContainsString('outcome `success`', $markdown);
        self::assertStringContainsString('Composer `2.8.12`, duration `125 ms`, exit `1`', $markdown);
        self::assertStringContainsString('command argv: `["composer","update","fixture/dependency:^2.0"]`', $markdown);
        self::assertStringContainsString('diagnostic for `fixture/dependency ^2.0` (outcome `success`, exit `0`)', $markdown);
        self::assertStringContainsString('fixture/blocker 1.0.0 requires fixture/dependency (^1.0)', $markdown);
        self::assertStringContainsString('candidate lock: SHA-256', $markdown);
        self::assertStringContainsString('content hash `candidate-content`, packages `0`', $markdown);
        self::assertStringContainsString('`transitive-package-conflict` `fixture/dependency`', $markdown);
        self::assertStringContainsString('requested `^2.0`; blocker `fixture/blocker`; locked `1.0.0`; conflict `^1.0`', $markdown);
        self::assertStringContainsString('dependency path: `fixture/blocker -> fixture/dependency`', $markdown);
        self::assertStringContainsString('option: Upgrade or replace `fixture/blocker`.', $markdown);
        self::assertStringContainsString('(direct dependency; major-version jump; families: laravel, symfony)', $markdown);
        self::assertStringContainsString('`vendor/transitive`: added `-` -> `1.0.0` (transitive dependency)', $markdown);
        self::assertStringContainsString('source reference: `source-before` -> `source-after`', $markdown);
        self::assertStringContainsString('dist reference: `dist-before` -> `dist-after`', $markdown);
        self::assertStringContainsString('## Source Inventory', $markdown);
        self::assertStringContainsString('## Actionable Source Impact', $markdown);
        self::assertStringContainsString('src/Example.php:12', $markdown);
        self::assertStringContainsString('## Framework Findings', $markdown);
        self::assertStringContainsString('## Framework Transition Guidance', $markdown);
        self::assertStringContainsString('hop `7` -> `9`: `supported`', $markdown);
        self::assertStringContainsString('`laravel` `medium`', $markdown);
        self::assertStringContainsString('Framework migration guidance requires review.', $markdown);
        self::assertStringContainsString('## Root Constraint Changes', $markdown);
        self::assertStringContainsString('fixture/dependency', $markdown);
        self::assertStringContainsString('## Staged Plan', $markdown);
        self::assertStringContainsString('Regenerate the lock file.', $markdown);
        self::assertStringContainsString('## Test Guidance', $markdown);
        self::assertStringContainsString('composer test', $markdown);
        self::assertStringContainsString('A framework finding requires review.', $markdown);
        self::assertStringContainsString('`dependency_resolution`: `1-1` hours', $markdown);
        self::assertStringContainsString('The project test suite is representative.', $markdown);
        self::assertStringContainsString('## Uncertainties', $markdown);
        self::assertStringContainsString('Composer scenario could not run.', $markdown);
        self::assertStringContainsString('## Evidence', $markdown);
        self::assertStringContainsString('source-1', $markdown);
        self::assertStringContainsString('"line":12', $markdown);
        self::assertStringContainsString(
            'Context: ``{"file":"src/Example.php","line":12,"detail":"keep  repeated spaces and `embedded code`"}``',
            $markdown
        );

        $stagedCanonical = $report->toArray();
        $stagedCanonical['staged_resolution'] = $this->stagedResolutionFixture();
        $stagedMarkdown = $writer->renderCanonical($stagedCanonical);

        self::assertStringContainsString('**laravel-10-to-11** (`10` -> `11`)', $stagedMarkdown);
        self::assertStringContainsString('state chain: predecessor `predecessor-sha`; input `input-sha`; output `none`', $stagedMarkdown);
        self::assertStringContainsString('effective platform: `platform-sha`; completeness `complete`; profile `profile-sha`', $stagedMarkdown);
        self::assertStringContainsString('Composer policy: `execution-sha`; mode `restricted`; stage duration `12 ms`', $stagedMarkdown);
        self::assertStringContainsString('stage evidence: `stage-evidence-1`', $stagedMarkdown);
        self::assertStringContainsString('attempt `1` `root_constraint_remediation`: outcome `solver_failure`; duration `12 ms`; selected no; blockers `stage-blocker-1`', $stagedMarkdown);
        self::assertStringContainsString('analyzer-only root change `phpunit/phpunit`: `^10.0` -> `^11.0.1`', $stagedMarkdown);
        self::assertStringContainsString('selected package change `laravel/framework`: `10.48.28` -> `11.44.7`', $stagedMarkdown);
        self::assertStringContainsString('original-source finding (`high`): Review the Laravel 11 application structure.', $stagedMarkdown);
        self::assertStringContainsString('lifecycle `persists` (detected@1 -> persists@2)', $stagedMarkdown);
        self::assertStringContainsString('path `vendor/testing-package -> phpunit/phpunit`', $stagedMarkdown);

        $pathlessCanonical = $report->toArray();
        $pathlessCanonical['blockers'][0]['dependency_path'] = [];
        self::assertStringContainsString(
            'dependency path: unknown',
            $writer->renderCanonical($pathlessCanonical)
        );

        $profile = [
            'schema_version' => '1.0',
            'completeness' => 'complete',
            'sha256' => str_repeat('a', 64),
            'provenance' => 'file',
            'supported_classes' => ['php', 'extension', 'library', 'php_subtype', 'composer_platform'],
            'closed_world' => true,
            'toolchain_bound' => ['composer', 'composer-plugin-api', 'composer-runtime-api'],
            'effective' => [
                [
                    'name' => 'composer',
                    'class' => 'composer_platform',
                    'state' => 'present',
                    'version' => '2.8.12',
                    'provenance' => 'profile',
                    'simulation' => 'toolchain_bound',
                ],
                [
                    'name' => 'composer-plugin-api',
                    'class' => 'composer_platform',
                    'state' => 'present',
                    'version' => '2.6.0',
                    'provenance' => 'profile',
                    'simulation' => 'toolchain_bound',
                ],
                [
                    'name' => 'composer-runtime-api',
                    'class' => 'composer_platform',
                    'state' => 'present',
                    'version' => '2.2.2',
                    'provenance' => 'profile',
                    'simulation' => 'toolchain_bound',
                ],
                [
                    'name' => 'ext-curl',
                    'class' => 'extension',
                    'state' => 'absent',
                    'version' => null,
                    'provenance' => 'closed_world',
                    'simulation' => 'composer_config',
                ],
                [
                    'name' => 'php',
                    'class' => 'php',
                    'state' => 'present',
                    'version' => '8.3.0',
                    'provenance' => 'profile',
                    'simulation' => 'composer_config',
                ],
            ],
        ];
        $canonical = $report->toArray();
        $canonical['request_summary']['target_platform_profile'] = array_intersect_key(
            $profile,
            array_flip(['schema_version', 'completeness', 'sha256', 'provenance'])
        );
        $canonical['platform']['profile'] = $profile;
        $profileMarkdown = $writer->renderCanonical($canonical);

        self::assertStringContainsString('completeness `complete`', $profileMarkdown);
        self::assertStringContainsString('SHA-256 `' . str_repeat('a', 64) . '`', $profileMarkdown);
        self::assertStringContainsString('complete closed-world modeling', $profileMarkdown);
        self::assertStringContainsString('Toolchain-bound packages: `composer`, `composer-plugin-api`, `composer-runtime-api`', $profileMarkdown);
        self::assertStringContainsString('`composer-plugin-api` (`composer_platform`): `present` at `2.6.0`; provenance `profile`; simulation `toolchain_bound`', $profileMarkdown);
        self::assertStringContainsString('`ext-curl` (`extension`): `absent`; provenance `closed_world`; simulation `composer_config`', $profileMarkdown);
        self::assertStringContainsString('`php` (`php`): `present` at `8.3.0`; provenance `profile`; simulation `composer_config`', $profileMarkdown);

        $canonical['platform']['profile']['effective'] = [];
        self::assertStringContainsString('Effective platform decisions:' . PHP_EOL . '    - None.', $writer->renderCanonical($canonical));

        $canonical['request_summary']['target_platform_profile']['completeness'] = 'partial';
        $canonical['platform']['profile']['completeness'] = 'partial';
        $canonical['platform']['profile']['closed_world'] = false;
        self::assertStringContainsString(
            'partial and host-dependent; unlisted platform packages may come from the analyzer runtime.',
            $writer->renderCanonical($canonical)
        );
    }

    public function testItProjectsDiagnosticOutcomesAlongsideTheExitCode(): void
    {
        $writer = new MarkdownReportWriter();
        $canonical = $this->buildReport()->toArray();

        // An unusable probe and an ordinary non-zero `composer prohibits` exit must not read alike.
        $canonical['resolution']['scenarios'][0]['diagnostics'][0]['outcome'] = ScenarioResult::OUTCOME_TIMEOUT;
        $timedOutMarkdown = $writer->renderCanonical($canonical);

        self::assertStringContainsString(
            '  - diagnostic for `fixture/dependency ^2.0` (outcome `timeout`, exit `0`), command argv:',
            $timedOutMarkdown
        );
        self::assertStringNotContainsString(
            'diagnostic for `fixture/dependency ^2.0` (exit `0`)',
            $timedOutMarkdown
        );

        // Schema 0.7 documents predate the field, so no outcome is invented for them.
        unset($canonical['resolution']['scenarios'][0]['diagnostics'][0]['outcome']);
        self::assertStringContainsString(
            '  - diagnostic for `fixture/dependency ^2.0` (outcome `not recorded`, exit `0`), command argv:',
            $writer->renderCanonical($canonical)
        );

        $canonical['resolution']['scenarios'][0]['diagnostics'][0]['outcome'] = null;
        self::assertStringContainsString(
            '  - diagnostic for `fixture/dependency ^2.0` (outcome `unknown`, exit `0`), command argv:',
            $writer->renderCanonical($canonical)
        );
    }

    public function testItReportsAbsentComposerExecutionAndStagedResolutionAsUnrecorded(): void
    {
        $canonical = $this->buildReport()->toArray();
        unset($canonical['composer_execution'], $canonical['staged_resolution']);

        $markdown = (new MarkdownReportWriter())->renderCanonical($canonical);

        self::assertStringContainsString(
            '## Composer Execution Provenance' . PHP_EOL . '- Not recorded by this report schema.' . PHP_EOL,
            $markdown
        );
        self::assertStringContainsString(
            '## Staged Composer Resolution' . PHP_EOL . '- Not recorded by this report schema.' . PHP_EOL,
            $markdown
        );

        // A document without the block must not receive invented compatible-mode answers.
        self::assertStringNotContainsString('- Mode: ', $markdown);
        self::assertStringNotContainsString('project_and_global', $markdown);
        self::assertStringNotContainsString('- Executable selection:', $markdown);
        self::assertStringNotContainsString('- Timeouts:', $markdown);
        self::assertStringNotContainsString('- Inheritance:', $markdown);
        self::assertStringNotContainsString('- Side effects', $markdown);
        self::assertStringNotContainsString('300 s', $markdown);
        self::assertStringNotContainsString('60 s', $markdown);

        // Staged domain values must not be fabricated, and the headline drops the token.
        self::assertStringNotContainsString('schema_does_not_include_staged_resolution', $markdown);
        self::assertStringNotContainsString('Staged:', $markdown);
        self::assertStringNotContainsString('- Execution: ', $markdown);
        self::assertStringNotContainsString('- Blocker registry:', $markdown);
        self::assertStringNotContainsString('- Staged source-impact registry:', $markdown);
        self::assertStringContainsString('| Schema: `0.8` | Tool: `php-upgrade-preflight 0.3.0`', $markdown);
    }

    public function testItReportsAbsentComposerExecutionFieldsAsUnrecordedRatherThanDefaults(): void
    {
        $canonical = $this->buildReport()->toArray();
        unset(
            $canonical['composer_execution']['repository_source_mode'],
            $canonical['composer_execution']['scenario_timeout_seconds'],
            $canonical['composer_execution']['global_configuration_inherited'],
            $canonical['composer_execution']['composer_version']
        );
        $canonical['composer_execution']['diagnostic_timeout_seconds'] = 900;

        $markdown = (new MarkdownReportWriter())->renderCanonical($canonical);

        self::assertStringContainsString('Composer version: `not recorded`;', $markdown);
        self::assertStringContainsString('repositories: `not recorded`', $markdown);
        self::assertStringContainsString('- Timeouts: scenario `not recorded`; diagnostic `900 s`;', $markdown);
        self::assertStringContainsString('- Inheritance: global configuration not recorded;', $markdown);
        self::assertStringNotContainsString('project_and_global', $markdown);
        self::assertStringNotContainsString('300 s', $markdown);
    }

    public function testItProjectsRecordedComposerSideEffectFlags(): void
    {
        $writer = new MarkdownReportWriter();
        $canonical = $this->buildReport()->toArray();

        self::assertStringContainsString(
            '- Side effects: scripts disabled; plugins disabled; installation disabled; audit disabled;'
                . ' interaction disabled; progress disabled.',
            $writer->renderCanonical($canonical)
        );
        // The unconditional claim the writer used to print is gone.
        self::assertStringNotContainsString(
            '- Side effects disabled: scripts, plugins, installation, audit, interaction, and progress.',
            $writer->renderCanonical($canonical)
        );

        $canonical['composer_execution']['scripts_enabled'] = true;
        unset($canonical['composer_execution']['progress_enabled']);

        self::assertStringContainsString(
            '- Side effects: scripts enabled; plugins disabled; installation disabled; audit disabled;'
                . ' interaction disabled; progress not recorded.',
            $writer->renderCanonical($canonical)
        );
    }

    public function testItProjectsStagedSourceImpactOccurrencesInsteadOfCountingThem(): void
    {
        $canonical = $this->buildReport()->toArray();
        $canonical['staged_resolution'] = $this->stagedResolutionFixture();
        $canonical['staged_resolution']['source_impact'] = [
            [
                'id' => 'source-impact-2c2e82dc82629e7036e5',
                'stage_ids' => ['laravel-10-to-11'],
                'affected_package' => 'laravel/framework',
                'severity' => 'high',
                'occurrences' => [
                    [
                        'file' => 'app/Http/Kernel.php',
                        'symbol' => 'Illuminate\\Foundation\\Http\\Kernel',
                        'usage_type' => 'class_extends',
                        'line' => 7,
                        'evidence' => ['source-1'],
                    ],
                    [
                        'file' => 'app/Http/Kernel.php',
                        'symbol' => 'Illuminate\\Foundation\\Http\\Kernel',
                        'usage_type' => 'static_call',
                        'line' => null,
                        'evidence' => ['source-1'],
                    ],
                ],
                'evidence' => ['source-1'],
            ],
            [
                'stage_ids' => [],
                'affected_package' => null,
                'severity' => 'low',
                'occurrences' => [],
                'evidence' => ['source-1'],
            ],
        ];

        $markdown = (new MarkdownReportWriter())->renderCanonical($canonical);

        // No count appears, because no such number exists in the canonical report.
        self::assertStringNotContainsString('occurrence(s)', $markdown);
        self::assertStringNotContainsString('unique occurrence', $markdown);
        self::assertStringContainsString(
            '  - `source-impact-2c2e82dc82629e7036e5` stages `laravel-10-to-11`: `high` impact for'
                . ' `laravel/framework` (evidence: `source-1`)' . PHP_EOL
                . '    - `class_extends` `Illuminate\\Foundation\\Http\\Kernel` in `app/Http/Kernel.php:7`'
                . ' (evidence: `source-1`)' . PHP_EOL
                . '    - `static_call` `Illuminate\\Foundation\\Http\\Kernel` in `app/Http/Kernel.php`'
                . ' (evidence: `source-1`)',
            $markdown
        );

        // A finding without an id must not borrow a synthesized identifier.
        self::assertStringNotContainsString('legacy-source-impact', $markdown);
        self::assertStringContainsString(
            '  - `not recorded` stages `none`: `low` impact for `package unknown` (evidence: `source-1`)',
            $markdown
        );
    }

    public function testItReportsSynthesizedIdentifiersInDirectFindingsAndPlanStagesAsUnrecorded(): void
    {
        $canonical = $this->buildReport()->toArray();
        // An id-less direct finding: schema 0.6 documents predate the hashed identifier.
        $canonical['source_impact'] = [[
            'stage_ids' => [],
            'affected_package' => 'laravel/framework',
            'ownership' => 'unknown',
            'relevance' => 'framework_rule',
            'reason' => 'Referenced by active laravel compatibility guidance.',
            'severity' => 'high',
            'occurrences' => [[
                'file' => 'app/Http/Kernel.php',
                'symbol' => 'Illuminate\\Foundation\\Http\\Kernel',
                'usage_type' => 'class_extends',
                'line' => 7,
                'evidence' => ['source-1'],
            ]],
            'evidence' => ['source-1'],
        ]];
        $canonical['plan']['stages'][0]['stage_id'] = null;

        $markdown = (new MarkdownReportWriter())->renderCanonical($canonical);

        self::assertStringNotContainsString('legacy-source-impact', $markdown);
        self::assertStringNotContainsString('executed stage `direct-final`', $markdown);
        self::assertStringContainsString('- `not recorded` `high` impact for `laravel/framework`', $markdown);
        self::assertStringContainsString('executed stage `not recorded`', $markdown);
        self::assertStringContainsString(
            '  - `class_extends` `Illuminate\\Foundation\\Http\\Kernel` in `app/Http/Kernel.php:7`'
                . ' (evidence: `source-1`)',
            $markdown
        );
    }

    public function testItReportsUnrecordedStageMeasurementsAndSeparatesRiskFromEffort(): void
    {
        $canonicalNote = 'This stage assessment inspects the original project source snapshot;'
            . ' it does not assume edits from an earlier stage were applied.';

        $canonical = $this->buildReport()->toArray();
        $canonical['staged_resolution'] = [
            'execution_state' => 'evaluated',
            'status' => 'blocked',
            'provider' => 'laravel',
            'stop_reason' => null,
            'blocker_registry' => [],
            'stages' => [
                $this->minimalStage('laravel-10-to-11', [
                    'risk' => ['stage_id' => 'laravel-10-to-11', 'level' => 'high'],
                ]),
                $this->minimalStage('laravel-11-to-12', [
                    'source_snapshot_note' => $canonicalNote,
                    'duration_ms' => 41,
                    'effort' => ['range_hours' => [4, 13], 'confidence' => 'low'],
                ]),
            ],
        ];

        $markdown = (new MarkdownReportWriter())->renderCanonical($canonical);

        // V5: the authored methodological claim is printed verbatim, never paraphrased.
        self::assertStringContainsString('  - ' . $canonicalNote, $markdown);
        self::assertStringNotContainsString('This assessment inspects the original project source snapshot.', $markdown);
        self::assertStringContainsString('  - Source snapshot note: not recorded.', $markdown);

        // V6: an absent measurement is not rendered as a measured zero.
        self::assertStringNotContainsString('stage duration `0 ms`', $markdown);
        self::assertStringNotContainsString('duration `0 ms`', $markdown);
        self::assertStringContainsString('stage duration `not recorded`', $markdown);
        self::assertStringContainsString('stage duration `41 ms`', $markdown);
        self::assertStringContainsString('outcome `solver_failure`; duration `not recorded`;', $markdown);

        // V8: a present risk level survives an absent effort estimate and vice versa.
        self::assertStringContainsString('  - risk for `laravel-10-to-11`: `high`' . PHP_EOL, $markdown);
        self::assertStringContainsString('  - effort: 4-13 hours (`low` confidence)' . PHP_EOL, $markdown);
        self::assertStringNotContainsString('risk for `laravel-11-to-12`', $markdown);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function minimalStage(string $id, array $overrides): array
    {
        return array_merge([
            'id' => $id,
            'from_major' => 10,
            'to_major' => 11,
            'execution_state' => 'evaluated',
            'resolution_status' => 'blocked',
            'selected_attempt' => null,
            'analysis_php' => '8.3.0',
            'source_snapshot' => 'original_project',
            'attempts' => [[
                'number' => 1,
                'strategy' => 'root_constraint_remediation',
                'scenario' => ['outcome' => 'solver_failure'],
                'selected' => false,
                'blocker_ids' => [],
                'root_constraint_changes' => [],
            ]],
            'package_changes' => [],
            'source_findings' => [],
            'stop_reason' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function stagedResolutionFixture(): array
    {
        return [
            'execution_state' => 'evaluated',
            'status' => 'blocked',
            'provider' => 'laravel',
            'stop_reason' => 'blocking_registry_not_cleared',
            'stages' => [[
                'id' => 'laravel-10-to-11',
                'from_major' => 10,
                'to_major' => 11,
                'execution_state' => 'evaluated',
                'resolution_status' => 'blocked',
                'selected_attempt' => null,
                'analysis_php' => '8.3.0',
                'source_snapshot' => 'original_project',
                'platform' => [
                    'effective_sha256' => 'platform-sha',
                    'completeness' => 'complete',
                    'profile_sha256' => 'profile-sha',
                ],
                'composer_execution' => [
                    'effective_sha256' => 'execution-sha',
                    'configuration' => ['mode' => 'restricted'],
                ],
                'duration_ms' => 12,
                'evidence' => ['stage-evidence-1'],
                'predecessor_state' => ['state_sha256' => 'predecessor-sha'],
                'input_state' => ['state_sha256' => 'input-sha'],
                'output_state' => null,
                'attempts' => [[
                    'number' => 1,
                    'strategy' => 'root_constraint_remediation',
                    'scenario' => ['outcome' => 'solver_failure', 'duration_ms' => 12],
                    'selected' => false,
                    'blocker_ids' => ['stage-blocker-1'],
                    'root_constraint_changes' => [[
                        'package' => 'phpunit/phpunit',
                        'from_constraint' => '^10.0',
                        'to_constraint' => '^11.0.1',
                    ]],
                ]],
                'package_changes' => [[
                    'name' => 'laravel/framework',
                    'from_version' => '10.48.28',
                    'to_version' => '11.44.7',
                ]],
                'source_findings' => [[
                    'severity' => 'high',
                    'summary' => 'Review the Laravel 11 application structure.',
                ]],
                'stop_reason' => 'blocking_registry_not_cleared',
            ]],
            'blocker_registry' => [[
                'id' => 'stage-blocker-1',
                'stage_id' => 'laravel-10-to-11',
                'category' => 'package_conflict',
                'subject' => 'phpunit/phpunit',
                'lifecycle' => 'persists',
                'lifecycle_history' => [
                    ['status' => 'detected', 'attempt' => 1],
                    ['status' => 'persists', 'attempt' => 2],
                ],
                'blocking_package' => 'vendor/testing-package',
                'constraint' => '^10.0',
                'dependency_path' => ['vendor/testing-package', 'phpunit/phpunit'],
            ]],
        ];
    }

    private function longStdoutLine(): string
    {
        return 'start-' . str_repeat('solver-detail-', 50) . '-end';
    }

    private function buildReport(): UpgradeReport
    {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            '7.4',
            '8.2',
            ['packages/core/src'],
            ['laravel'],
            'markdown',
            'upgrade-report.md'
        );
        $scenario = new Scenario('exact-target', $request->targets());
        $fullStdoutExcerpt = $this->longStdoutLine() . "\n```embedded fence```\nafter-fence";
        $evidence = new Evidence('source-1', Evidence::E3_PROJECT_SOURCE, 'Detected Vendor\\Package\\Client.', 'high', [
            'file' => 'src/Example.php',
            'line' => 12,
            'detail' => 'keep  repeated spaces and `embedded code`',
        ]);

        return new UpgradeReport(
            $request,
            new ProjectState($projectPath, new ComposerJson([
                'require' => ['fixture/dependency' => '^1.0'],
                'config' => ['platform' => ['php' => '7.4.33']],
            ]), new ComposerLock([])),
            [
                new ScenarioResult(
                    $scenario,
                    1,
                    $fullStdoutExcerpt,
                    'Composer executable unavailable.',
                    null,
                    'C:\\temp\\php-upgrade-preflight-debug',
                    ScenarioResult::FAILURE_OPERATIONAL,
                    '2.8.12',
                    ['composer', 'update', 'fixture/dependency:^2.0'],
                    125,
                    null,
                    [new ComposerDiagnostic(
                        'fixture/dependency',
                        '^2.0',
                        ['composer', 'prohibits', 'fixture/dependency', '^2.0', '--tree', '--locked'],
                        0,
                        'fixture/blocker 1.0.0 requires fixture/dependency (^1.0)',
                        ''
                    )],
                    ScenarioResult::OUTCOME_PROCESS_FAILURE,
                    true
                ),
                new ScenarioResult(
                    $scenario,
                    0,
                    'Resolved.',
                    '',
                    new ComposerLock(['content-hash' => 'candidate-content', 'packages' => []]),
                    null,
                    null,
                    '2.8.12',
                    ['composer', 'update', 'fixture/dependency'],
                    250
                ),
            ],
            new LockDiff([
                new PackageChange(
                    'vendor/package',
                    'upgraded',
                    '1.0.0',
                    '2.0.0',
                    true,
                    'source-before',
                    'source-after',
                    'dist-before',
                    'dist-after',
                    true,
                    ['laravel', 'symfony']
                ),
                new PackageChange('vendor/transitive', 'added', null, '1.0.0'),
            ]),
            [new Blocker(
                'transitive-package-conflict',
                'fixture/dependency',
                'A transitive constraint blocks the target.',
                'high',
                ['source-1'],
                '^2.0',
                'fixture/blocker',
                '1.0.0',
                '^1.0',
                ['fixture/blocker', 'fixture/dependency'],
                ['Upgrade or replace `fixture/blocker`.']
            )],
            [new SourceUsage('src/Example.php', 'Vendor\\Package\\Client', 'static_call', ['source-1'], 12)],
            [new CompatibilityFinding(
                'laravel',
                'medium',
                'Framework migration guidance requires review.',
                ['source-1'],
                [['from_major' => 7, 'to_major' => 9]]
            )],
            new RiskSummary('low', ['A framework finding requires review.']),
            new EffortEstimate(
                [1, 2],
                'low',
                ['dependency_resolution' => [1, 1], 'tests_and_debugging' => [0, 1]],
                ['The project test suite is representative.']
            ),
            ['Composer scenario could not run.'],
            [$evidence],
            [new RootConstraintChange(
                'fixture/dependency',
                'added',
                null,
                '^2.0',
                'The requested target is not declared as a root requirement.',
                ['source-1']
            )],
            [new PlanStage('dependencies', 'Resolve the dependency transition.', ['Regenerate the lock file.'], ['source-1'])],
            [new TestGuidance('project-test-suite', 'Run regression coverage.', 'composer test', 'required')],
            [],
            [new FrameworkGuidance(
                'laravel',
                7,
                9,
                FrameworkGuidance::SUPPORTED,
                [new FrameworkHop(7, 9, FrameworkHop::SUPPORTED, 'laravel-7-to-9-direct', ['source-1'])],
                [],
                ['source-1']
            )]
        );
    }
}
