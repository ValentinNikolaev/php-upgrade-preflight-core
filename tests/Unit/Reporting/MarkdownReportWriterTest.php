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
        $longStdoutLine = 'start-' . str_repeat('solver-detail-', 50) . '-end';
        $fullStdoutExcerpt = $longStdoutLine . "\n```embedded fence```\nafter-fence";
        $evidence = new Evidence('source-1', Evidence::E3_PROJECT_SOURCE, 'Detected Vendor\\Package\\Client.', 'high', [
            'file' => 'src/Example.php',
            'line' => 12,
            'detail' => 'keep  repeated spaces and `embedded code`',
        ]);
        $report = new UpgradeReport(
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
                    )]
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
            [new CompatibilityFinding('laravel', 'warning', 'Framework migration guidance requires review.', ['source-1'])],
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
            [new TestGuidance('project-test-suite', 'Run regression coverage.', 'composer test', 'required')]
        );

        $markdown = (new MarkdownReportWriter())->render($report);

        self::assertStringContainsString('## Composer Scenarios', $markdown);
        self::assertStringContainsString('Schema: `0.6`', $markdown);
        self::assertStringContainsString('Tool: `php-upgrade-preflight 0.1.0`', $markdown);
        self::assertStringContainsString('## Analysis Request', $markdown);
        self::assertStringContainsString('Current PHP: `7.4`', $markdown);
        self::assertStringContainsString('Target PHP: `8.2.0`', $markdown);
        self::assertStringContainsString('Source paths: `packages/core/src`', $markdown);
        self::assertStringContainsString('Framework integrations: `laravel`', $markdown);
        self::assertStringContainsString('Requested format: `markdown`', $markdown);
        self::assertStringContainsString('Output destination: `upgrade-report.md`', $markdown);
        self::assertStringContainsString('## Project State', $markdown);
        self::assertStringContainsString('Composer platform PHP: `7.4.33`', $markdown);
        self::assertStringContainsString('`fixture/dependency`: `^1.0`', $markdown);
        self::assertStringContainsString('Composer executable unavailable.', $markdown);
        self::assertStringContainsString($longStdoutLine, $markdown);
        self::assertStringContainsString('```embedded fence```', $markdown);
        self::assertStringContainsString('after-fence', $markdown);
        self::assertStringContainsString('    ````text', $markdown);
        self::assertStringContainsString('temporary workspace: `C:\\temp\\php-upgrade-preflight-debug`', $markdown);
        self::assertStringContainsString('outcome `process_failure`', $markdown);
        self::assertStringContainsString('outcome `success`', $markdown);
        self::assertStringContainsString('Composer `2.8.12`, duration `125 ms`, exit `1`', $markdown);
        self::assertStringContainsString('command argv: `["composer","update","fixture/dependency:^2.0"]`', $markdown);
        self::assertStringContainsString('diagnostic for `fixture/dependency ^2.0` (exit `0`)', $markdown);
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
        self::assertStringContainsString('## Source Impact', $markdown);
        self::assertStringContainsString('src/Example.php:12', $markdown);
        self::assertStringContainsString('## Framework Findings', $markdown);
        self::assertStringContainsString('`laravel` `warning`', $markdown);
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
    }
}
