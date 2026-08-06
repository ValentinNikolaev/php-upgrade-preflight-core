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
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
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

    /** @param list<Evidence> $evidence */
    private function report(array $evidence): UpgradeReport
    {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        return new UpgradeReport(
            $request,
            new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([])),
            [],
            new LockDiff([]),
            [],
            array_map(
                static fn (Evidence $item): SourceUsage => new SourceUsage('src/Example.php', 'Fixture\\Example', 'class_reference', [$item->id]),
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
