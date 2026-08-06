<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Reporting;

use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
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
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PhpUpgradePreflight\Core\Reporting\JsonReportWriter;
use PHPUnit\Framework\TestCase;

final class JsonReportWriterTest extends TestCase
{
    public function testItRendersTheCanonicalReportAsPrettyPrintedJson(): void
    {
        $json = (new JsonReportWriter())->render($this->report([
            new Evidence('evidence-1', Evidence::E3_PROJECT_SOURCE, 'Valid evidence.'),
        ]));

        self::assertStringEndsWith("\n", $json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.6', $decoded['metadata']['schema_version']);
        self::assertSame('php-upgrade-preflight', $decoded['metadata']['tool']['name']);
        self::assertSame('0.1.0', $decoded['metadata']['tool']['version']);
        self::assertSame('evidence-1', $decoded['evidence'][0]['id']);
        self::assertSame('Valid evidence.', $decoded['evidence'][0]['summary']);
        self::assertSame('unknown', $decoded['resolution']['status']);
    }

    public function testInvalidUtf8FailsInsteadOfProducingAnEmptyReport(): void
    {
        $this->expectException(\JsonException::class);

        (new JsonReportWriter())->render($this->report([
            new Evidence('invalid-utf8', Evidence::E3_PROJECT_SOURCE, "Invalid \xB1 text"),
        ]));
    }

    public function testLongMultibyteDiagnosticTreeOutputRemainsValidJson(): void
    {
        $targets = new UpgradeTargetSet([new UpgradeTarget('fixture/dependency', '^2.0')]);
        $diagnostic = new ComposerDiagnostic(
            'fixture/dependency',
            '^2.0',
            ['composer', 'prohibits', 'fixture/dependency', '^2.0', '--tree', '--locked'],
            0,
            str_repeat('a', 3999) . '├' . str_repeat('b', 100),
            ''
        );
        $scenario = new ScenarioResult(
            new Scenario('exact-target', $targets),
            2,
            '',
            'Solver failed.',
            null,
            null,
            ScenarioResult::FAILURE_SOLVER,
            '2.8.12',
            ['composer', 'update', 'fixture/dependency'],
            10,
            null,
            [$diagnostic]
        );

        $json = (new JsonReportWriter())->render($this->report([], [$scenario]));
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            str_repeat('a', 3999),
            $decoded['resolution']['scenarios'][0]['diagnostics'][0]['stdout_excerpt']
        );
    }

    /** @param list<Evidence> $evidence @param list<ScenarioResult> $scenarios */
    private function report(array $evidence, array $scenarios = []): UpgradeReport
    {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        return new UpgradeReport(
            $request,
            new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([])),
            $scenarios,
            new LockDiff([]),
            [],
            array_map(
                static fn (Evidence $item): SourceUsage => new SourceUsage('src/Example.php', 'Fixture\\Example', 'class_reference', [$item->id()]),
                $evidence
            ),
            [],
            new RiskSummary('low', []),
            new EffortEstimate([0, 0], 'high', [], []),
            [],
            $evidence
        );
    }
}
