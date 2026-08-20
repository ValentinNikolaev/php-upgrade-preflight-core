<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\PackageMetadataLookupResult;
use PHPUnit\Framework\TestCase;

final class PackageMetadataLookupResultTest extends TestCase
{
    public function testFoundResultExposesValuesCountsAndArrayProjection(): void
    {
        $result = new PackageMetadataLookupResult(
            PackageMetadataLookupResult::STATUS_FOUND,
            PackageMetadataLookupResult::REASON_PACKAGE_FOUND,
            'vendor/package',
            '^2.0',
            ['2.1.0', '2.0.0'],
            ['2.1.0'],
            5,
            3,
            'metadata warning'
        );

        self::assertSame(PackageMetadataLookupResult::STATUS_FOUND, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_PACKAGE_FOUND, $result->reason());
        self::assertSame('vendor/package', $result->package());
        self::assertSame('^2.0', $result->constraint());
        self::assertSame(['2.1.0', '2.0.0'], $result->versions());
        self::assertSame(['2.1.0'], $result->matchingVersions());
        self::assertSame(5, $result->availableVersionCount());
        self::assertSame(3, $result->matchingVersionCount());
        self::assertTrue($result->hasMatchingVersion());
        self::assertSame('metadata warning', $result->diagnostic());
        self::assertSame([
            'status' => PackageMetadataLookupResult::STATUS_FOUND,
            'reason' => PackageMetadataLookupResult::REASON_PACKAGE_FOUND,
            'package' => 'vendor/package',
            'constraint' => '^2.0',
            'versions' => ['2.1.0', '2.0.0'],
            'matching_versions' => ['2.1.0'],
            'available_version_count' => 5,
            'matching_version_count' => 3,
            'has_matching_version' => true,
            'versions_truncated' => true,
            'matching_versions_truncated' => true,
            'diagnostic' => 'metadata warning',
        ], $result->toArray());
    }

    public function testDefaultCountsAndNonFoundMatchingStateAreDerived(): void
    {
        $found = new PackageMetadataLookupResult(
            PackageMetadataLookupResult::STATUS_FOUND,
            PackageMetadataLookupResult::REASON_PACKAGE_FOUND,
            'vendor/package',
            '^3.0',
            ['2.0.0']
        );
        $notFound = new PackageMetadataLookupResult(
            PackageMetadataLookupResult::STATUS_NOT_FOUND,
            PackageMetadataLookupResult::REASON_PACKAGE_NOT_FOUND,
            'vendor/package',
            '^3.0'
        );

        self::assertSame(1, $found->availableVersionCount());
        self::assertSame(0, $found->matchingVersionCount());
        self::assertFalse($found->hasMatchingVersion());
        self::assertNull($notFound->hasMatchingVersion());
        self::assertFalse($found->toArray()['versions_truncated']);
        self::assertFalse($found->toArray()['matching_versions_truncated']);
    }

    public function testUnsupportedStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported package metadata lookup status');

        new PackageMetadataLookupResult('maybe', 'reason', 'vendor/package', '^2.0');
    }

    /**
     * @dataProvider undersizedCountProvider
     * @param list<string> $versions
     * @param list<string> $matchingVersions
     */
    public function testCountsCannotBeSmallerThanRetainedLists(
        array $versions,
        array $matchingVersions,
        int $availableCount,
        int $matchingCount
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('version counts cannot be smaller');

        new PackageMetadataLookupResult(
            PackageMetadataLookupResult::STATUS_FOUND,
            PackageMetadataLookupResult::REASON_PACKAGE_FOUND,
            'vendor/package',
            '^2.0',
            $versions,
            $matchingVersions,
            $availableCount,
            $matchingCount
        );
    }

    /** @return list<array{list<string>, list<string>, int, int}> */
    public function undersizedCountProvider(): array
    {
        return [
            [['2.0.0'], [], 0, 0],
            [[], ['2.0.0'], 0, 0],
        ];
    }

    public function testOnlyFoundResultsMayCarryVersionMetadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only a found package may expose version metadata');

        new PackageMetadataLookupResult(
            PackageMetadataLookupResult::STATUS_UNVERIFIED,
            PackageMetadataLookupResult::REASON_PROCESS_FAILURE,
            'vendor/package',
            '^2.0',
            ['2.0.0']
        );
    }
}
