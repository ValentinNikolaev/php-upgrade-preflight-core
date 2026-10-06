<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\TargetNormalizer;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class TargetNormalizerTest extends TestCase
{
    public function testItProducesACanonicalTargetSet(): void
    {
        $targets = (new TargetNormalizer())->normalize([
            new UpgradeTarget('Vendor/Package', ' ^2.0 '),
            new UpgradeTarget('alpha/package', '^1.0'),
            new UpgradeTarget('vendor/package', '^2.0'),
            new UpgradeTarget('PHP', 'v8.1'),
        ], '8.1.0');

        self::assertSame('8.1.0', $targets->targetPhp());
        self::assertSame([
            ['package' => 'alpha/package', 'constraint' => '^1.0'],
            ['package' => 'php', 'constraint' => '8.1.0'],
            ['package' => 'vendor/package', 'constraint' => '^2.0'],
        ], $targets->toArray());
    }

    public function testItRejectsConflictingTargets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting constraints');

        (new TargetNormalizer())->normalize([
            new UpgradeTarget('vendor/package', '^1.0'),
            new UpgradeTarget('vendor/package', '^2.0'),
        ]);
    }
}
