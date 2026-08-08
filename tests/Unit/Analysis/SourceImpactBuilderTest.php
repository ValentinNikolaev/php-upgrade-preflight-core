<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\SourceImpactBuilder;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PHPUnit\Framework\TestCase;

final class SourceImpactBuilderTest extends TestCase
{
    public function testItOnlyPromotesInventoryReferencedByFrameworkGuidance(): void
    {
        $related = new SourceUsage('app/Kernel.php', 'Legacy\\Middleware', 'middleware_reference', ['source-1'], 12);
        $unrelated = new SourceUsage('app/Service.php', 'App\\Value', 'import', ['source-2'], 8);

        $impact = (new SourceImpactBuilder())->build(
            [$related, $unrelated],
            [new CompatibilityFinding('laravel', 'high', 'Review the middleware.', ['source-1', 'docs-1'])]
        );

        self::assertCount(1, $impact);
        self::assertNull($impact[0]->affectedPackage());
        self::assertSame('unknown', $impact[0]->ownership());
        self::assertSame('framework_rule', $impact[0]->relevance());
        self::assertSame('high', $impact[0]->severity());
        self::assertSame([$related], $impact[0]->occurrences());
        self::assertSame(['source-1', 'docs-1'], $impact[0]->evidence());
        self::assertStringContainsString('package ownership has not been established', $impact[0]->reason());
    }
}
