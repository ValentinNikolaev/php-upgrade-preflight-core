<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Source;

use PhpUpgradePreflight\Core\Source\SymbolOwnershipIndex;
use PHPUnit\Framework\TestCase;

final class SymbolOwnershipIndexTest extends TestCase
{
    public function testExactLookupsRespectSymbolKindAndPhpCaseRules(): void
    {
        $index = new SymbolOwnershipIndex();
        $index->addExact('Vendor\\Package\\Shared', 'vendor/class', 'classmap', 'class');
        $index->addExact('Vendor\\Package\\Shared', 'vendor/function', 'files', 'function');
        $index->addExact('Vendor\\Package\\FLAG', 'vendor/constant', 'files', 'constant');

        self::assertSame(['vendor/class'], $index->lookup('vendor\\package\\shared', false, 'class')['owners']);
        self::assertSame(['vendor/function'], $index->lookup('VENDOR\\PACKAGE\\SHARED', false, 'function')['owners']);
        self::assertSame(['vendor/constant'], $index->lookup('Vendor\\Package\\FLAG', false, 'constant')['owners']);
        self::assertSame([], $index->lookup('Vendor\\Package\\flag', false, 'constant')['owners']);
        self::assertSame([], $index->lookup('Vendor\\Package\\FLAG', false, 'function')['owners']);
    }

    public function testUnsupportedSymbolKindsAreRejected(): void
    {
        $index = new SymbolOwnershipIndex();

        $this->expectException(\InvalidArgumentException::class);
        $index->addExact('Vendor\\Package\\Thing', 'vendor/package', 'files', 'variable');
    }
}
