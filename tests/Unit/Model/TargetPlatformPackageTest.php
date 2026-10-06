<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\TargetPlatformPackage;
use PHPUnit\Framework\TestCase;

final class TargetPlatformPackageTest extends TestCase
{
    /**
     * @dataProvider packageClassProvider
     */
    public function testItClassifiesSupportedPlatformPackages(
        string $name,
        string $expectedClass,
        string $expectedSimulation
    ): void {
        $package = new TargetPlatformPackage($name, '1.2.3');

        self::assertSame($expectedClass, $package->class());
        self::assertSame($expectedClass, $package->packageClass());
        self::assertSame($expectedSimulation, $package->simulation());
        self::assertSame('1.2.3', $package->composerValue());
    }

    /** @return list<array{string, string, string}> */
    public function packageClassProvider(): array
    {
        return [
            ['php', TargetPlatformPackage::CLASS_PHP, TargetPlatformPackage::SIMULATION_COMPOSER_CONFIG],
            ['ext-json', TargetPlatformPackage::CLASS_EXTENSION, TargetPlatformPackage::SIMULATION_COMPOSER_CONFIG],
            ['lib-openssl', TargetPlatformPackage::CLASS_LIBRARY, TargetPlatformPackage::SIMULATION_COMPOSER_CONFIG],
            ['php-64bit', TargetPlatformPackage::CLASS_PHP_SUBTYPE, TargetPlatformPackage::SIMULATION_COMPOSER_CONFIG],
            ['php-ipv6', TargetPlatformPackage::CLASS_PHP_SUBTYPE, TargetPlatformPackage::SIMULATION_COMPOSER_CONFIG],
            ['php-zts', TargetPlatformPackage::CLASS_PHP_SUBTYPE, TargetPlatformPackage::SIMULATION_COMPOSER_CONFIG],
            ['php-debug', TargetPlatformPackage::CLASS_PHP_SUBTYPE, TargetPlatformPackage::SIMULATION_COMPOSER_CONFIG],
            ['composer', TargetPlatformPackage::CLASS_COMPOSER_PLATFORM, TargetPlatformPackage::SIMULATION_TOOLCHAIN_BOUND],
            ['composer-plugin-api', TargetPlatformPackage::CLASS_COMPOSER_PLATFORM, TargetPlatformPackage::SIMULATION_TOOLCHAIN_BOUND],
            ['composer-runtime-api', TargetPlatformPackage::CLASS_COMPOSER_PLATFORM, TargetPlatformPackage::SIMULATION_TOOLCHAIN_BOUND],
        ];
    }

    public function testItNormalizesPhpAndModelsAbsenceWithoutAFalseVersion(): void
    {
        $php = new TargetPlatformPackage(' PHP ', 'v8.3');
        $absent = new TargetPlatformPackage('EXT-XDEBUG', false);

        self::assertSame('8.3.0', $php->version());
        self::assertSame('ext-xdebug', $absent->name());
        self::assertTrue($absent->isAbsent());
        self::assertNull($absent->version());
        self::assertFalse($absent->composerValue());
        self::assertSame(TargetPlatformPackage::PROVENANCE_PROFILE, $absent->provenance());
    }

    public function testPresenceOnlyExtensionKeepsUnknownVersionOutOfCanonicalOutput(): void
    {
        $package = TargetPlatformPackage::fromPresenceOnlyExtension('ext-json');

        self::assertTrue($package->isPresentWithoutVersion());
        self::assertNull($package->version());
        self::assertSame('0', $package->composerValue());
        self::assertNull($package->toArray()['version']);
        self::assertSame(
            ['composer', 'composer-plugin-api', 'composer-runtime-api'],
            TargetPlatformPackage::toolchainBoundNames()
        );
    }

    /** @dataProvider invalidPackageProvider */
    public function testItRejectsUnsupportedNamesValuesAndConstraints(string $name, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TargetPlatformPackage($name, $value);
    }

    /** @return list<array{string, mixed}> */
    public function invalidPackageProvider(): array
    {
        return [
            ['vendor/package', '1.0.0'],
            ['ext-', '1.0.0'],
            ['ext-a..b', '1.0.0'],
            ['ext-a._b', '1.0.0'],
            ['lib-a--b', '1.0.0'],
            ['php-32bit', '8.3.0'],
            ['ext-json', '^1.0'],
            ['ext-json', ''],
            ['ext-json', true],
            ['php', false],
            ['php', '8.3.0-beta1'],
        ];
    }

    public function testItsSerializedDecisionUsesOnlyCanonicalFields(): void
    {
        $package = new TargetPlatformPackage('composer-runtime-api', false);

        self::assertSame([
            'name' => 'composer-runtime-api',
            'class' => 'composer_platform',
            'state' => 'absent',
            'version' => null,
            'provenance' => 'profile',
            'simulation' => 'toolchain_bound',
        ], $package->toArray());
        self::assertTrue($package->isToolchainBound());
    }

    public function testSupportedNameCheckUsesTheSameComposerCompatibleGrammar(): void
    {
        self::assertTrue(TargetPlatformPackage::isSupportedName('EXT-JSON'));
        self::assertTrue(TargetPlatformPackage::isSupportedName('lib-curl-openssl'));
        self::assertFalse(TargetPlatformPackage::isSupportedName('ext-a..b'));
        self::assertFalse(TargetPlatformPackage::isSupportedName('lib-a--b'));
    }

    public function testItRejectsPresenceOnlyNonExtensionsAndUnsupportedProvenance(): void
    {
        try {
            TargetPlatformPackage::fromPresenceOnlyExtension('lib-icu');
            self::fail('Expected a presence-only library to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Only an extension', $exception->getMessage());
        }

        try {
            new TargetPlatformPackage('ext-json', '8.3.0', 'invented');
            self::fail('Expected unsupported provenance to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('provenance', $exception->getMessage());
        }

        self::assertSame('present', (new TargetPlatformPackage('ext-json', '8.3.0'))->state());
    }
}
