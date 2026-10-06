<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\TargetPlatformPackage;
use PhpUpgradePreflight\Core\Model\TargetPlatformProfile;
use PHPUnit\Framework\TestCase;

final class TargetPlatformProfileTest extends TestCase
{
    public function testItLoadsAndSerializesADeterministicallySortedCompleteProfile(): void
    {
        $profile = TargetPlatformProfile::fromArray([
            'schema_version' => '1.0',
            'completeness' => 'complete',
            'packages' => [
                'php-zts' => false,
                'php' => '8.3',
                'lib-openssl' => '3.0.13',
                'ext-json' => '8.3.0',
                'composer-runtime-api' => '2.2.2',
            ],
        ]);

        self::assertTrue($profile->isComplete());
        self::assertSame('1.0', $profile->schemaVersion());
        self::assertSame('complete', $profile->completeness());
        self::assertSame('php_api', $profile->provenance());
        self::assertSame($profile->digest(), $profile->sha256());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $profile->digest());
        self::assertSame(
            ['composer-runtime-api', 'ext-json', 'lib-openssl', 'php', 'php-zts'],
            array_map(static fn (TargetPlatformPackage $package): string => $package->name(), $profile->packages())
        );
        self::assertSame(
            ['composer', 'composer-plugin-api', 'composer-runtime-api'],
            $profile->toolchainBoundPackages()
        );
        self::assertSame([
            'ext-json' => '8.3.0',
            'lib-openssl' => '3.0.13',
            'php' => '8.3.0',
            'php-zts' => false,
        ], $profile->composerPlatformOverrides());

        $serialized = $profile->toArray();
        self::assertSame([
            'schema_version',
            'completeness',
            'sha256',
            'provenance',
            'supported_classes',
            'closed_world',
            'toolchain_bound',
            'effective',
        ], array_keys($serialized));
        self::assertTrue($serialized['closed_world']);
        self::assertSame('profile', $serialized['effective'][0]['provenance']);
    }

    public function testEquivalentOrderingAndMatchingDuplicatesProduceOneDigestAndDecision(): void
    {
        $first = TargetPlatformProfile::fromArray([
            'schema_version' => '1.0',
            'completeness' => 'partial',
            'packages' => ['ext-json' => '8.3.0', 'php' => '8.3.0'],
        ]);
        $second = new TargetPlatformProfile('partial', [
            new TargetPlatformPackage('PHP', '8.3'),
            new TargetPlatformPackage('EXT-JSON', '8.3.0'),
            new TargetPlatformPackage('ext-json', '8.3.0'),
        ]);

        self::assertSame($first->digest(), $second->digest());
        self::assertCount(2, $second->packages());
        self::assertSame('8.3.0', $second->package('PHP')->version());
    }

    public function testPublicConstructionCanonicalizesPackageProvenanceIndependentlyOfOrder(): void
    {
        $requestPackage = new TargetPlatformPackage(
            'EXT-JSON',
            '8.3.0',
            TargetPlatformPackage::PROVENANCE_REQUEST
        );
        $configuredPackage = new TargetPlatformPackage(
            'ext-json',
            '8.3.0',
            TargetPlatformPackage::PROVENANCE_COMPOSER_CONFIG
        );
        $library = new TargetPlatformPackage(
            'lib-icu',
            '73.2',
            TargetPlatformPackage::PROVENANCE_CLOSED_WORLD
        );
        $first = new TargetPlatformProfile('partial', [$requestPackage, $configuredPackage, $library]);
        $reversed = new TargetPlatformProfile('partial', [$library, $configuredPackage, $requestPackage]);

        self::assertSame($first->digest(), $reversed->digest());
        self::assertSame($first->toArray(), $reversed->toArray());
        self::assertSame(
            ['profile', 'profile'],
            array_column($first->toArray()['effective'], 'provenance')
        );
    }

    public function testJsonAndFileLoadersUseFileProvenanceWithoutExposingInput(): void
    {
        $profile = TargetPlatformProfile::fromJson(
            '{"schema_version":"1.0","completeness":"partial","packages":{"ext-json":false}}'
        );

        self::assertSame('file', $profile->provenance());
        self::assertSame($profile->summary(), array_intersect_key(
            $profile->toArray(),
            array_flip(['schema_version', 'completeness', 'sha256', 'provenance'])
        ));

        $sensitive = 'credential-secret-value';
        try {
            TargetPlatformProfile::fromJson('{"secret":"' . $sensitive . '"');
            self::fail('Expected invalid profile JSON to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString($sensitive, $exception->getMessage());
        }

        $missingPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-profile-' . bin2hex(random_bytes(8)) . '.json';
        try {
            TargetPlatformProfile::fromFile($missingPath);
            self::fail('Expected a missing profile file to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString($missingPath, $exception->getMessage());
        }
    }

    public function testJsonRequiresPackagesToBeAnObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid field types');

        TargetPlatformProfile::fromJson(
            '{"schema_version":"1.0","completeness":"partial","packages":[]}'
        );
    }

    public function testJsonRejectsDuplicateObjectKeysBeforeNormalization(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate JSON object keys');

        TargetPlatformProfile::fromJson(
            '{"schema_version":"1.0","completeness":"partial","packages":{"ext-json":"8.3.0","ext-json":false}}'
        );
    }

    /**
     * @dataProvider invalidProfileProvider
     * @param array<string, mixed> $data
     */
    public function testItRejectsMalformedOrFalselyCompleteProfiles(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TargetPlatformProfile::fromArray($data);
    }

    /** @return list<array{array<mixed>}> */
    public function invalidProfileProvider(): array
    {
        return [
            [['schema_version' => '1.0', 'completeness' => 'complete', 'packages' => []]],
            [['schema_version' => '1.0', 'completeness' => 'complete', 'packages' => ['php' => false]]],
            [['schema_version' => '2.0', 'completeness' => 'partial', 'packages' => []]],
            [['schema_version' => '1.0', 'completeness' => 'unknown', 'packages' => []]],
            [['schema_version' => '1.0', 'completeness' => 'partial']],
            [['schema_version' => '1.0', 'completeness' => 'partial', 'packages' => [], 'path' => '/private']],
            [['schema_version' => '1.0', 'completeness' => 'partial', 'packages' => ['ext-json' => null]]],
            [['schema_version' => '1.0', 'completeness' => 'partial', 'packages' => ['ext-a..b' => '1.0.0']]],
            [['schema_version' => '1.0', 'completeness' => 'partial', 'packages' => ['lib-a--b' => '1.0.0']]],
        ];
    }

    public function testItRejectsContradictoryNormalizedDuplicates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contradictory duplicate');

        new TargetPlatformProfile('partial', [
            new TargetPlatformPackage('EXT-JSON', '8.3.0'),
            new TargetPlatformPackage('ext-json', false),
        ]);
    }

    public function testItRejectsInvalidPublicConstructorInputs(): void
    {
        foreach ([
            static fn () => new TargetPlatformProfile('partial', [], 'invented'),
            static fn () => new TargetPlatformProfile('partial', [new \stdClass()]), // @phpstan-ignore argument.type
            static fn () => new TargetPlatformProfile('partial', [TargetPlatformPackage::fromPresenceOnlyExtension('ext-json')]),
        ] as $construct) {
            try {
                $construct();
                self::fail('Expected invalid target-platform profile input to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testLoadersRejectInvalidTopLevelTypesAndScanNestedJsonValues(): void
    {
        foreach (['[]', '"profile"', 'false'] as $json) {
            try {
                TargetPlatformProfile::fromJson($json);
                self::fail('Expected a non-object profile to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Target platform profile JSON must contain an object.', $exception->getMessage());
            }
        }

        foreach ([
            '{"schema_version":"1.0","completeness":"partial","packages":{},"extra":[]}',
            '{"schema_version":"1.0","completeness":"partial","packages":{},"extra":["escaped\\tvalue","second"]}',
        ] as $json) {
            try {
                TargetPlatformProfile::fromJson($json);
                self::fail('Expected an extra profile field to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('exactly', $exception->getMessage());
            }
        }

        try {
            TargetPlatformProfile::fromArray([
                'schema_version' => 1,
                'completeness' => 'partial',
                'packages' => [],
            ]);
            self::fail('Expected invalid field types to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('invalid field types', $exception->getMessage());
        }

        self::assertSame(
            ['php', 'extension', 'library', 'php_subtype', 'composer_platform'],
            TargetPlatformProfile::fromArray([
                'schema_version' => '1.0',
                'completeness' => 'partial',
                'packages' => [],
            ])->supportedClasses()
        );
    }
}
