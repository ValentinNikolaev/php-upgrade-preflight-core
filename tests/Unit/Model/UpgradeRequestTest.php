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

    public function testItValidatesAndNormalizesExplicitSourcePaths(): void
    {
        $request = new UpgradeRequest(
            dirname(__DIR__, 5),
            [new UpgradeTarget('vendor/package', '^2.0')],
            '7.4',
            null,
            ['packages/core/src', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src']
        );

        self::assertSame('7.4', $request->fromPhp());
        self::assertSame(['packages/core/src'], $request->sourcePaths());
    }

    /**
     * @dataProvider invalidPathAndVersionProvider
     * @param list<string> $sourcePaths
     */
    public function testItRejectsInvalidSourcePathsAndCurrentPhpVersions(?string $fromPhp, array $sourcePaths, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new UpgradeRequest(
            dirname(__DIR__, 5),
            [new UpgradeTarget('vendor/package', '^2.0')],
            $fromPhp,
            null,
            $sourcePaths
        );
    }

    /** @return list<array{?string, list<string>, string}> */
    public function invalidPathAndVersionProvider(): array
    {
        return [
            ['^7.4', [], 'Current PHP version'],
            [null, [''], 'must not be empty'],
            [null, ['missing'], 'does not exist'],
            [null, [dirname(__DIR__, 6)], 'inside the analyzed project'],
        ];
    }
}
