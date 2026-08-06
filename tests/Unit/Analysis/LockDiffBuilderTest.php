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
            [
                'name' => 'vendor/removed',
                'version' => '3.0.0',
                'source' => ['reference' => 'removed-source'],
                'dist' => ['reference' => 'removed-dist'],
            ],
            ['name' => 'vendor/beta', 'version' => '2.4.0'],
            [
                'name' => 'vendor/alpha',
                'version' => 'v1.9.0',
                'source' => ['reference' => 'alpha-source-before'],
                'dist' => ['reference' => 'alpha-dist-before'],
            ],
            ['name' => 'vendor/reference-added', 'version' => '1.0.0'],
            [
                'name' => 'vendor/dist-ref',
                'version' => '1.0.0',
                'source' => ['reference' => 'source-stable'],
                'dist' => ['reference' => 'dist-before'],
            ],
            [
                'name' => 'vendor/source-ref',
                'version' => 'dev-main',
                'source' => ['reference' => 'source-before'],
                'dist' => ['reference' => 'dist-stable'],
            ],
        ]);
        $after = $this->lock([
            [
                'name' => 'vendor/new',
                'version' => '1.0.0',
                'source' => ['reference' => 'new-source'],
                'dist' => ['reference' => 'new-dist'],
            ],
            [
                'name' => 'vendor/alpha',
                'version' => 'v2.0.0',
                'source' => ['reference' => 'alpha-source-after'],
                'dist' => ['reference' => 'alpha-dist-after'],
            ],
            ['name' => 'vendor/unchanged', 'version' => '1.0.0'],
            ['name' => 'vendor/beta', 'version' => '1.8.0'],
            [
                'name' => 'vendor/reference-added',
                'version' => '1.0.0',
                'source' => ['reference' => 'added-source-ref'],
                'dist' => ['reference' => 'added-dist-ref'],
            ],
            [
                'name' => 'vendor/dist-ref',
                'version' => '1.0.0',
                'source' => ['reference' => 'source-stable'],
                'dist' => ['reference' => 'dist-after'],
            ],
            [
                'name' => 'vendor/source-ref',
                'version' => 'dev-main',
                'source' => ['reference' => 'source-after'],
                'dist' => ['reference' => 'dist-stable'],
            ],
        ]);

        $changes = (new LockDiffBuilder())->build($before, $after)->toArray()['package_changes'];

        self::assertSame([
            [
                'name' => 'vendor/alpha',
                'change_type' => 'upgraded',
                'from_version' => 'v1.9.0',
                'to_version' => 'v2.0.0',
                'major_change' => true,
                'from_source_reference' => 'alpha-source-before',
                'to_source_reference' => 'alpha-source-after',
                'from_dist_reference' => 'alpha-dist-before',
                'to_dist_reference' => 'alpha-dist-after',
            ],
            [
                'name' => 'vendor/beta',
                'change_type' => 'downgraded',
                'from_version' => '2.4.0',
                'to_version' => '1.8.0',
                'major_change' => true,
                'from_source_reference' => null,
                'to_source_reference' => null,
                'from_dist_reference' => null,
                'to_dist_reference' => null,
            ],
            [
                'name' => 'vendor/dist-ref',
                'change_type' => 'changed',
                'from_version' => '1.0.0',
                'to_version' => '1.0.0',
                'major_change' => false,
                'from_source_reference' => 'source-stable',
                'to_source_reference' => 'source-stable',
                'from_dist_reference' => 'dist-before',
                'to_dist_reference' => 'dist-after',
            ],
            [
                'name' => 'vendor/new',
                'change_type' => 'added',
                'from_version' => null,
                'to_version' => '1.0.0',
                'major_change' => false,
                'from_source_reference' => null,
                'to_source_reference' => 'new-source',
                'from_dist_reference' => null,
                'to_dist_reference' => 'new-dist',
            ],
            [
                'name' => 'vendor/reference-added',
                'change_type' => 'changed',
                'from_version' => '1.0.0',
                'to_version' => '1.0.0',
                'major_change' => false,
                'from_source_reference' => null,
                'to_source_reference' => 'added-source-ref',
                'from_dist_reference' => null,
                'to_dist_reference' => 'added-dist-ref',
            ],
            [
                'name' => 'vendor/removed',
                'change_type' => 'removed',
                'from_version' => '3.0.0',
                'to_version' => null,
                'major_change' => false,
                'from_source_reference' => 'removed-source',
                'to_source_reference' => null,
                'from_dist_reference' => 'removed-dist',
                'to_dist_reference' => null,
            ],
            [
                'name' => 'vendor/source-ref',
                'change_type' => 'changed',
                'from_version' => 'dev-main',
                'to_version' => 'dev-main',
                'major_change' => false,
                'from_source_reference' => 'source-before',
                'to_source_reference' => 'source-after',
                'from_dist_reference' => 'dist-stable',
                'to_dist_reference' => 'dist-stable',
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

    /** @param list<array<string, mixed>> $packages */
    private function lock(array $packages): ComposerLock
    {
        return new ComposerLock(['packages' => $packages, 'packages-dev' => []]);
    }
}
