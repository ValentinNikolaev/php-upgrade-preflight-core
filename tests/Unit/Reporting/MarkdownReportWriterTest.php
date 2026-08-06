<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Reporting;

use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceUsage;
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
        $scenario = new Scenario('exact-target', $request->targets);
        $evidence = new Evidence('source-1', Evidence::E3_PROJECT_SOURCE, 'Detected Vendor\\Package\\Client.', 'high', [
            'file' => 'src/Example.php',
            'line' => 12,
        ]);
        $report = new UpgradeReport(
            $request,
            new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([])),
            [new ScenarioResult($scenario, 1, '', 'Composer executable unavailable.', null, null, ScenarioResult::FAILURE_OPERATIONAL)],
            new LockDiff([]),
            [],
            [new SourceUsage('src/Example.php', 'Vendor\\Package\\Client', 'static_call', ['source-1'], 12)],
            [],
            new RiskSummary('low', []),
            new EffortEstimate([1, 2], 'low', [], []),
            ['Composer scenario could not run.'],
            [$evidence]
        );

        $markdown = (new MarkdownReportWriter())->render($report);

        self::assertStringContainsString('## Composer Scenarios', $markdown);
        self::assertStringContainsString('Composer executable unavailable.', $markdown);
        self::assertStringContainsString('## Source Impact', $markdown);
        self::assertStringContainsString('src/Example.php:12', $markdown);
        self::assertStringContainsString('## Uncertainties', $markdown);
        self::assertStringContainsString('Composer scenario could not run.', $markdown);
        self::assertStringContainsString('## Evidence', $markdown);
        self::assertStringContainsString('source-1', $markdown);
        self::assertStringContainsString('"line":12', $markdown);
    }
}
