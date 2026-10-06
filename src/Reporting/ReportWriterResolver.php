<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Reporting;

use PhpUpgradePreflight\Core\Model\ReportFormat;

/**
 * Selects the report projection for a normalized {@see ReportFormat} value.
 *
 * JSON is the canonical report, so every format other than Markdown resolves to
 * the canonical writer.
 *
 * This lives in core rather than in an entry-point package because every entry
 * point renders the same {@see \PhpUpgradePreflight\Core\Model\UpgradeReport}:
 * the CLI and the Artisan command must not be able to drift into choosing
 * different projections for the same `--format` value.
 */
final class ReportWriterResolver
{
    public function resolve(string $format): ReportWriter
    {
        return $format === ReportFormat::MARKDOWN
            ? new MarkdownReportWriter()
            : new JsonReportWriter();
    }
}
