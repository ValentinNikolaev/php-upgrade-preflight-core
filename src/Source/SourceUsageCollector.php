<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

use PhpParser\NodeVisitor;

/**
 * A parser visitor that reports the usages it observed while traversing one file.
 *
 * Core owns the shape of a usage record; it does not own the vocabulary. Framework
 * adapters contribute collectors through the optional
 * {@see \PhpUpgradePreflight\Core\Framework\SourceUsageVisitorProvider} port and
 * remain the only owners of the usage_type values they emit.
 */
interface SourceUsageCollector extends NodeVisitor
{
    /** @return list<array{symbol: string, usage_type: string, line: int}> */
    public function usages(): array;
}
