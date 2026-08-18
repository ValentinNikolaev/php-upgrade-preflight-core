<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Framework;

use PhpUpgradePreflight\Core\Source\SourceUsageCollector;

/**
 * Optional v0.3 extension point. The required v0.2 FrameworkIntegration and
 * FrameworkTransitionProvider interfaces deliberately remain unchanged.
 *
 * An integration that implements this port contributes framework-shaped source
 * inspection to the scan. Core attaches the contributed collectors only for
 * integrations that are active for the analyzed project, so a project without
 * that framework never pays for, or reports, its vocabulary.
 *
 * Each collector traverses a file alone, so its traversal-control return values
 * never truncate core's framework-neutral inventory or another adapter's collector.
 * A provider that throws, yields a value that is not a SourceUsageCollector, or
 * contributes a collector that throws loses its contribution and is reported as a
 * documented uncertainty with evidence; it does not end the analysis. See
 * docs/adapters.md for the full contract.
 */
interface SourceUsageVisitorProvider
{
    /**
     * @param string $relativeFile project-relative path of the file being traversed
     * @return iterable<SourceUsageCollector>
     */
    public function sourceUsageVisitors(string $relativeFile): iterable;
}
