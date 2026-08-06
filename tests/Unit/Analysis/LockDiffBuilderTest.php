<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\LockDiffBuilder;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PHPUnit\Framework\TestCase;

final class LockDiffBuilderTest extends TestCase
{
    public function testItClassifiesAddedRemovedUpgradedAndDowngradedPackagesDeterministically(): void
    {
        $before = $this->lock([
            ['name' => 'vendor/unchanged', 'version' => '1.0.0'],
            ['name' => 'vendor/removed', 'version' => '3.0.0'],
            ['name' => 'vendor/beta', 'version' => '2.4.0'],
            ['name' => 'vendor/alpha', 'version' => 'v1.9.0'],
        ]);
        $after = $this->lock([
            ['name' => 'vendor/new', 'version' => '1.0.0'],
            ['name' => 'vendor/alpha', 'version' => 'v2.0.0'],
            ['name' => 'vendor/unchanged', 'version' => '1.0.0'],
            ['name' => 'vendor/beta', 'version' => '1.8.0'],
        ]);

        $changes = (new LockDiffBuilder())->build($before, $after)->toArray()['package_changes'];

        self::assertSame([
            [
                'name' => 'vendor/alpha',
                'change_type' => 'upgraded',
                'from_version' => 'v1.9.0',
                'to_version' => 'v2.0.0',
                'major_change' => true,
            ],
            [
                'name' => 'vendor/beta',
                'change_type' => 'downgraded',
                'from_version' => '2.4.0',
                'to_version' => '1.8.0',
                'major_change' => true,
            ],
            [
                'name' => 'vendor/new',
                'change_type' => 'added',
                'from_version' => null,
                'to_version' => '1.0.0',
                'major_change' => false,
            ],
            [
                'name' => 'vendor/removed',
                'change_type' => 'removed',
                'from_version' => '3.0.0',
                'to_version' => null,
                'major_change' => false,
            ],
        ], $changes);
    }

    public function testItProducesNoChangesForEquivalentLocksAcrossRuntimeAndDevSections(): void
    {
        $before = new ComposerLock([
            'packages' => [['name' => 'vendor/runtime', 'version' => '1.0.0']],
            'packages-dev' => [['name' => 'vendor/dev', 'version' => '2.0.0']],
        ]);
        $after = new ComposerLock([
            'packages-dev' => [['name' => 'vendor/dev', 'version' => '2.0.0']],
            'packages' => [['name' => 'vendor/runtime', 'version' => '1.0.0']],
        ]);

        self::assertSame([], (new LockDiffBuilder())->build($before, $after)->packageChanges());
    }

    /** @param list<array{name: string, version: string}> $packages */
    private function lock(array $packages): ComposerLock
    {
        return new ComposerLock(['packages' => $packages, 'packages-dev' => []]);
    }
}
