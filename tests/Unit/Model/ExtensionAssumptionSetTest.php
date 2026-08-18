<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ExtensionAssumption;
use PhpUpgradePreflight\Core\Model\ExtensionAssumptionSet;
use PhpUpgradePreflight\Core\Model\TargetPlatformPackage;
use PHPUnit\Framework\TestCase;

final class ExtensionAssumptionSetTest extends TestCase
{
    public function testMatchingDuplicatesCollapseDeterministically(): void
    {
        $set = ExtensionAssumptionSet::fromInputs(
            ['ext-json:8.3.0', 'EXT-JSON:8.3.0'],
            []
        );

        self::assertCount(1, $set->all());
        self::assertSame('ext-json', $set->all()[0]->name());
    }

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

    /** @dataProvider malformedExtensionNameProvider */
    public function testItRejectsNamesOutsideComposerExtensionGrammar(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must use Composer ext-name syntax');

        ExtensionAssumptionSet::fromInputs([$name], []);
    }

    /** @return list<array{string}> */
    public function malformedExtensionNameProvider(): array
    {
        return [
            ['ext-a..b'],
            ['ext-a._b'],
            ['ext-a--b'],
        ];
    }

    public function testOnlyExtensionPlatformPackagesBecomeAssumptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only extension platform packages');

        ExtensionAssumption::fromPlatformPackage(new TargetPlatformPackage('lib-icu', '73.2'));
    }
}
