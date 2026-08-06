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
}
