<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\LockDiffBuilder;
use PhpUpgradePreflight\Core\Framework\PackageFamilyClassifier;
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
        ], ['vendor/alpha', 'vendor/removed']);
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
        ], ['vendor/alpha', 'vendor/new']);

        $changes = (new LockDiffBuilder())->build($before, $after)->toArray()['package_changes'];

        self::assertSame([
            [
                'name' => 'vendor/alpha',
                'change_type' => 'upgraded',
                'from_version' => 'v1.9.0',
                'to_version' => 'v2.0.0',
                'direct' => true,
                'major_change' => true,
                'package_families' => [],
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
                'direct' => false,
                'major_change' => true,
                'package_families' => [],
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
                'direct' => false,
                'major_change' => false,
                'package_families' => [],
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
                'direct' => true,
                'major_change' => false,
                'package_families' => [],
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
                'direct' => false,
                'major_change' => false,
                'package_families' => [],
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
                'direct' => true,
                'major_change' => false,
                'package_families' => [],
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
                'direct' => false,
                'major_change' => false,
                'package_families' => [],
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

    public function testItDoesNotClaimAMajorJumpWhenEitherVersionHasNoNumericMajor(): void
    {
        $before = $this->lock([['name' => 'vendor/package', 'version' => 'dev-main']]);
        $after = $this->lock([['name' => 'vendor/package', 'version' => '2.0.0']]);

        $changes = (new LockDiffBuilder())->build($before, $after)->packageChanges();

        self::assertCount(1, $changes);
        self::assertFalse($changes[0]->isMajorChange());
    }

    public function testItAppliesOpaquePackageFamiliesFromGenericClassifiersDeterministically(): void
    {
        $before = $this->lock([
            ['name' => 'vendor/package', 'version' => '1.0.0'],
            ['name' => 'other/package', 'version' => '1.0.0'],
        ]);
        $after = $this->lock([
            ['name' => 'vendor/package', 'version' => '2.0.0'],
            ['name' => 'other/package', 'version' => '2.0.0'],
        ]);
        $first = new FixturePackageFamilyClassifier([
            'vendor/package' => ['zeta', 'shared', ''],
        ]);
        $second = new FixturePackageFamilyClassifier([
            'vendor/package' => ['alpha', 'shared'],
        ]);

        $changes = (new LockDiffBuilder())->build($before, $after, [$first, $second])->packageChanges();

        self::assertSame([], $changes[0]->packageFamilies());
        self::assertSame(['alpha', 'shared', 'zeta'], $changes[1]->packageFamilies());
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @param list<string> $directPackageNames
     */
    private function lock(array $packages, array $directPackageNames = []): ComposerLock
    {
        return new ComposerLock(['packages' => $packages, 'packages-dev' => []], $directPackageNames);
    }
}

final class FixturePackageFamilyClassifier implements PackageFamilyClassifier
{
    /** @var array<string, list<string>> */
    private array $families;

    /** @param array<string, list<string>> $families */
    public function __construct(array $families)
    {
        $this->families = $families;
    }

    public function packageFamilies(string $packageName): array
    {
        return $this->families[$packageName] ?? [];
    }
}
