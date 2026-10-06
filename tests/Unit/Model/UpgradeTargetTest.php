<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use InvalidArgumentException;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class UpgradeTargetTest extends TestCase
{
    public function testItCreatesATargetFromAPackageAndConstraint(): void
    {
        $target = UpgradeTarget::fromString('laravel/framework:^9.0');

        self::assertSame('laravel/framework', $target->package());
        self::assertSame('^9.0', $target->constraint());
        self::assertSame([
            'package' => 'laravel/framework',
            'constraint' => '^9.0',
        ], $target->toArray());
    }

    public function testItNormalizesCaseAndSurroundingWhitespace(): void
    {
        $target = new UpgradeTarget(' Vendor/Package ', ' ^2.0 ');

        self::assertSame('vendor/package', $target->package());
        self::assertSame('^2.0', $target->constraint());
    }

    public function testItNormalizesPlatformTargetsWithoutRewritingTheirConstraint(): void
    {
        $target = new UpgradeTarget('PHP', 'v8.1');

        self::assertSame('php', $target->package());
        self::assertSame('v8.1', $target->constraint());
    }

    public function testFromStringNormalizesBeforeExposingTheTarget(): void
    {
        $target = UpgradeTarget::fromString(' Laravel/Framework : ^9.0 ');

        self::assertSame('laravel/framework', $target->package());
        self::assertSame('^9.0', $target->constraint());
    }

    /**
     * @dataProvider invalidTargetProvider
     */
    public function testItRejectsTargetsWithoutAPackageAndConstraint(string $target): void
    {
        $this->expectException(InvalidArgumentException::class);

        UpgradeTarget::fromString($target);
    }

    /** @return list<array{string}> */
    public function invalidTargetProvider(): array
    {
        return [
            ['laravel/framework'],
            [':^9.0'],
            ['laravel/framework:'],
        ];
    }

    /**
     * @dataProvider unresolvableTargetProvider
     */
    public function testItRejectsUnresolvablePackagesAndConstraints(
        string $package,
        string $constraint,
        string $message
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new UpgradeTarget($package, $constraint);
    }

    /** @return list<array{string, string, string}> */
    public function unresolvableTargetProvider(): array
    {
        return [
            ['invalid', '^1.0', 'Invalid Composer target package'],
            ['', '^1.0', 'Invalid Composer target package'],
            ['vendor//package', '^1.0', 'Invalid Composer target package'],
            ['vendor/package', '', 'non-empty constraint'],
            ['vendor/package', '   ', 'non-empty constraint'],
            ['vendor/package', 'not a constraint', 'Invalid constraint'],
        ];
    }

    /**
     * @dataProvider platformPackageProvider
     */
    public function testItAcceptsComposerPlatformPackages(string $package): void
    {
        self::assertSame($package, (new UpgradeTarget($package, '^1.0'))->package());
    }

    /** @return list<array{string}> */
    public function platformPackageProvider(): array
    {
        return [
            ['php'],
            ['php-64bit'],
            ['ext-json'],
            ['lib-curl'],
            ['composer'],
            ['composer-plugin-api'],
            ['composer-runtime-api'],
        ];
    }
}
