<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class UpgradeRequestTest extends TestCase
{
    public function testItNormalizesFrameworksForDeterministicSchemaSafeSerialization(): void
    {
        $request = new UpgradeRequest(
            __DIR__,
            [new UpgradeTarget('vendor/package', '^2.0')],
            null,
            null,
            [],
            [' Symfony ', 'Laravel', 'laravel']
        );

        self::assertSame(['laravel', 'symfony'], $request->frameworks());
        self::assertSame(['laravel', 'symfony'], $request->toArray()['frameworks']);
    }

    public function testItRejectsAnEmptyFrameworkName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Framework at index 0 must not be empty.');

        new UpgradeRequest(
            __DIR__,
            [new UpgradeTarget('vendor/package', '^2.0')],
            null,
            null,
            [],
            ['  ']
        );
    }
}
