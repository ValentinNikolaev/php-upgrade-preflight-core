<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\FrameworkGuidance;
use PhpUpgradePreflight\Core\Model\FrameworkHop;
use PHPUnit\Framework\TestCase;

final class FrameworkGuidanceTest extends TestCase
{
    public function testPartialSupportRequiresAContiguousCoveredPrefix(): void
    {
        $guidance = new FrameworkGuidance(
            'fixture',
            8,
            11,
            FrameworkGuidance::PARTIALLY_SUPPORTED,
            [
                new FrameworkHop(8, 9, FrameworkHop::SUPPORTED, 'fixture-8-to-9', ['transition-1']),
                new FrameworkHop(9, 10, FrameworkHop::UNSUPPORTED, null, ['transition-2']),
                new FrameworkHop(10, 11, FrameworkHop::UNSUPPORTED, null, ['transition-3']),
            ],
            ['The 9 to 10 rule pack is missing.'],
            ['transition-1', 'transition-2', 'transition-3']
        );

        self::assertSame(FrameworkGuidance::PARTIALLY_SUPPORTED, $guidance->status());
        self::assertSame([['from_major' => 8, 'to_major' => 9]], $guidance->supportedHopReferences());
    }

    public function testItRejectsPostGapCoverage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('after the first missing hop');

        new FrameworkGuidance(
            'fixture',
            8,
            11,
            FrameworkGuidance::PARTIALLY_SUPPORTED,
            [
                new FrameworkHop(8, 9, FrameworkHop::UNSUPPORTED, null, ['transition-1']),
                new FrameworkHop(9, 10, FrameworkHop::SUPPORTED, 'fixture-9-to-10', ['transition-2']),
                new FrameworkHop(10, 11, FrameworkHop::UNSUPPORTED, null, ['transition-3']),
            ],
            ['The 8 to 9 rule pack is missing.'],
            ['transition-1', 'transition-2', 'transition-3']
        );
    }
}
