<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use InvalidArgumentException;
use PhpUpgradePreflight\Core\Model\PackageRef;
use PHPUnit\Framework\TestCase;

final class PackageRefTest extends TestCase
{
    public function testItNormalizesTheNameToLowercase(): void
    {
        self::assertSame('vendor/package', (new PackageRef('Vendor/Package', '1.0.0'))->name());
    }

    /**
     * @dataProvider invalidNameProvider
     */
    public function testItRejectsNamesThatAreNotComposerPackages(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Composer package name');

        new PackageRef($name, '1.0.0');
    }

    /** @return list<array{string}> */
    public function invalidNameProvider(): array
    {
        return [
            ['not a package'],
            [''],
            ['vendor'],
            ['vendor/'],
            ['/package'],
            ['vendor//package'],
            [' vendor/package'],
            ['vendor/package '],
        ];
    }

    /**
     * @dataProvider validNameProvider
     */
    public function testItAcceptsComposerPackageNames(string $name): void
    {
        self::assertSame($name, (new PackageRef($name, '1.0.0'))->name());
    }

    /** @return list<array{string}> */
    public function validNameProvider(): array
    {
        return [
            ['vendor/package'],
            ['graham-campbell/result-type'],
            ['symfony/polyfill-php80'],
            ['sebastian/code-unit-reverse-lookup'],
            ['php-upgrade-preflight/core'],
            ['dflydev/dot-access-data'],
        ];
    }

    public function testItExposesItsOwnRequirementsKeyedByLowercasedPackageName(): void
    {
        $package = new PackageRef('vendor/package', '1.0.0', false, null, null, false, null, [], [
            'PHP' => '^8.0',
            'Illuminate/Support' => '^9.0',
        ]);

        self::assertSame(['php' => '^8.0', 'illuminate/support' => '^9.0'], $package->requirements());
    }

    /**
     * Callers hand these constraints to a SemVer parser, so a lock row carrying a non-string
     * value must not surface as if it were a constraint.
     */
    public function testItDropsRequirementValuesThatAreNotConstraintStrings(): void
    {
        $package = new PackageRef('vendor/package', '1.0.0', false, null, null, false, null, [], [
            'vendor/good' => '^1.0',
            'vendor/bad' => ['^1.0'],
            'vendor/worse' => 7,
        ]);

        self::assertSame(['vendor/good' => '^1.0'], $package->requirements());
    }

    public function testRequirementsDefaultToAnEmptyMap(): void
    {
        self::assertSame([], (new PackageRef('vendor/package', '1.0.0'))->requirements());
    }

    /** The report schema is a published contract; requirements are an internal read model. */
    public function testRequirementsAreNotSerializedIntoTheReportShape(): void
    {
        $package = new PackageRef('vendor/package', '1.0.0', false, null, null, false, null, [], [
            'illuminate/support' => '^9.0',
        ]);

        self::assertArrayNotHasKey('requirements', $package->toArray());
        self::assertSame([
            'name',
            'version',
            'direct',
            'source_reference',
            'dist_reference',
            'abandoned',
            'abandoned_alternative',
            'abandoned_alternative_type',
        ], array_keys($package->toArray()));
    }

    public function testTheReplacementPackageUsesTheSameNameRule(): void
    {
        $packageAlternative = new PackageRef('vendor/package', '1.0.0', false, null, null, true, 'Vendor/Replacement');
        $urlAlternative = new PackageRef('vendor/package', '1.0.0', false, null, null, true, 'https://example.test');
        $proseAlternative = new PackageRef('vendor/package', '1.0.0', false, null, null, true, 'no replacement');

        self::assertSame('vendor/replacement', $packageAlternative->replacementPackage());
        self::assertSame('package', $packageAlternative->abandonedAlternativeType());
        self::assertNull($urlAlternative->replacementPackage());
        self::assertSame('url', $urlAlternative->abandonedAlternativeType());
        self::assertNull($proseAlternative->replacementPackage());
        self::assertSame('other', $proseAlternative->abandonedAlternativeType());
    }
}
