<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Reporting;

use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\ReportFormat;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Reporting\JsonReportWriter;
use PhpUpgradePreflight\Core\Reporting\MarkdownReportWriter;
use PhpUpgradePreflight\Core\Reporting\ReportWriter;
use PhpUpgradePreflight\Core\Reporting\ReportWriterResolver;
use PHPUnit\Framework\TestCase;

final class ReportWriterResolverTest extends TestCase
{
    public function testBothProjectionsShareTheReportWriterContract(): void
    {
        self::assertInstanceOf(ReportWriter::class, new JsonReportWriter());
        self::assertInstanceOf(ReportWriter::class, new MarkdownReportWriter());
    }

    public function testItResolvesMarkdownAndTreatsEveryOtherFormatAsCanonicalJson(): void
    {
        $resolver = new ReportWriterResolver();

        self::assertInstanceOf(MarkdownReportWriter::class, $resolver->resolve(ReportFormat::MARKDOWN));
        self::assertInstanceOf(JsonReportWriter::class, $resolver->resolve(ReportFormat::JSON));
        self::assertInstanceOf(JsonReportWriter::class, $resolver->resolve('anything-else'));
    }

    public function testResolvedWritersRenderExactlyWhatTheConcreteWritersRender(): void
    {
        $report = $this->report();
        $resolver = new ReportWriterResolver();

        self::assertSame(
            (new JsonReportWriter())->render($report),
            $resolver->resolve(ReportFormat::JSON)->render($report)
        );
        self::assertSame(
            (new MarkdownReportWriter())->render($report),
            $resolver->resolve(ReportFormat::MARKDOWN)->render($report)
        );
    }

    private function report(): UpgradeReport
    {
        $request = new UpgradeRequest(
            dirname(__DIR__, 5),
            [new UpgradeTarget('vendor/package', '^2.0')]
        );

        return new UpgradeReport(
            $request,
            new ProjectState($request->projectPath(), new ComposerJson([]), new ComposerLock([])),
            [],
            new LockDiff([]),
            [],
            [],
            [],
            new RiskSummary('low', []),
            new EffortEstimate([0, 0], 'high', [], []),
            [],
            []
        );
    }
}
