<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ExtensionAssumptionSet;
use PHPUnit\Framework\TestCase;

final class ExtensionAssumptionSetTest extends TestCase
{
    public function testItNormalizesAndOrdersPresenceAbsenceAndVersionAssumptions(): void
    {
        $assumptions = ExtensionAssumptionSet::fromInputs(
            ['EXT-JSON', 'ext-intl:72.1'],
            ['ext-xdebug']
        )->all();

        self::assertSame([
            ['name' => 'ext-intl', 'state' => 'present', 'version' => '72.1', 'provenance' => 'request'],
            ['name' => 'ext-json', 'state' => 'present', 'version' => null, 'provenance' => 'request'],
            ['name' => 'ext-xdebug', 'state' => 'absent', 'version' => null, 'provenance' => 'request'],
        ], array_map(static fn ($assumption): array => $assumption->toArray(), $assumptions));
    }

    public function testItRejectsContradictoryAndRepeatedValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ext-json');

        ExtensionAssumptionSet::fromInputs(['ext-json:8.2.0'], ['EXT-JSON']);
    }
}
