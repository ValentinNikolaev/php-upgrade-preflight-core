<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\BlockerAttribution;
use PhpUpgradePreflight\Core\Model\SolverRelation;
use PHPUnit\Framework\TestCase;

final class BlockerAttributionTest extends TestCase
{
    public function testAnUnattributedBlockerNamesNothing(): void
    {
        $attribution = BlockerAttribution::none();

        self::assertNull($attribution->blockingPackage());
        self::assertNull($attribution->lockedVersion());
        self::assertNull($attribution->conflict());
    }

    public function testAConstraintOnlyBlockerNamesNoPackage(): void
    {
        $attribution = BlockerAttribution::forConstraint('minimum-stability');

        self::assertNull($attribution->blockingPackage());
        self::assertNull($attribution->lockedVersion());
        self::assertSame('minimum-stability', $attribution->conflict());
    }

    public function testASolverRelationAttributesThePackageVersionAndConstraint(): void
    {
        $attribution = BlockerAttribution::fromRelation(new SolverRelation(
            'vendor/blocker',
            '1.0.0',
            SolverRelation::REQUIRES,
            'vendor/target',
            '^1.0'
        ));

        self::assertSame('vendor/blocker', $attribution->blockingPackage());
        self::assertSame('1.0.0', $attribution->lockedVersion());
        self::assertSame('^1.0', $attribution->conflict());
    }

    public function testAnExplicitAttributionKeepsEachFieldSeparate(): void
    {
        $attribution = new BlockerAttribution('vendor/blocker', '1.2.3', '^2.0');

        self::assertSame('vendor/blocker', $attribution->blockingPackage());
        self::assertSame('1.2.3', $attribution->lockedVersion());
        self::assertSame('^2.0', $attribution->conflict());
    }
}
