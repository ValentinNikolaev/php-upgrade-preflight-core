<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Reporting;

use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\LockDiff;
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
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);
        $scenario = new Scenario('exact-target', $request->targets());
        $evidence = new Evidence('source-1', Evidence::E3_PROJECT_SOURCE, 'Detected Vendor\\Package\\Client.', 'high', [
            'file' => 'src/Example.php',
            'line' => 12,
        ]);
        $report = new UpgradeReport(
            $request,
            new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([])),
            [
                new ScenarioResult(
                    $scenario,
                    1,
                    '',
                    'Composer executable unavailable.',
                    null,
                    null,
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
            new LockDiff([]),
            [],
            [new SourceUsage('src/Example.php', 'Vendor\\Package\\Client', 'static_call', ['source-1'], 12)],
            [],
            new RiskSummary('low', []),
            new EffortEstimate([1, 2], 'low', [], []),
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
        self::assertStringContainsString('Composer executable unavailable.', $markdown);
        self::assertStringContainsString('outcome `process_failure`', $markdown);
        self::assertStringContainsString('outcome `success`', $markdown);
        self::assertStringContainsString('Composer `2.8.12`, duration `125 ms`, exit `1`', $markdown);
        self::assertStringContainsString('command argv: `["composer","update","fixture/dependency:^2.0"]`', $markdown);
        self::assertStringContainsString('diagnostic for `fixture/dependency ^2.0` (exit `0`)', $markdown);
        self::assertStringContainsString('fixture/blocker 1.0.0 requires fixture/dependency (^1.0)', $markdown);
        self::assertStringContainsString('candidate lock: SHA-256', $markdown);
        self::assertStringContainsString('content hash `candidate-content`, packages `0`', $markdown);
        self::assertStringContainsString('## Source Impact', $markdown);
        self::assertStringContainsString('src/Example.php:12', $markdown);
        self::assertStringContainsString('## Root Constraint Changes', $markdown);
        self::assertStringContainsString('fixture/dependency', $markdown);
        self::assertStringContainsString('## Staged Plan', $markdown);
        self::assertStringContainsString('Regenerate the lock file.', $markdown);
        self::assertStringContainsString('## Test Guidance', $markdown);
        self::assertStringContainsString('composer test', $markdown);
        self::assertStringContainsString('## Uncertainties', $markdown);
        self::assertStringContainsString('Composer scenario could not run.', $markdown);
        self::assertStringContainsString('## Evidence', $markdown);
        self::assertStringContainsString('source-1', $markdown);
        self::assertStringContainsString('"line":12', $markdown);
    }
}
