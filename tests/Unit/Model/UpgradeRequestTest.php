<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ExtensionAssumption;
use PhpUpgradePreflight\Core\Model\TargetPlatformProfile;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class UpgradeRequestTest extends TestCase
{
    public function testItDerivesTargetPhpFromTheOptionalProfileAndSerializesOnlySafeMetadata(): void
    {
        $profile = TargetPlatformProfile::fromArray([
            'schema_version' => '1.0',
            'completeness' => 'complete',
            'packages' => ['php' => '8.3'],
        ]);
        $request = new UpgradeRequest(__DIR__, [], null, null, [], [], 'json', null, false, [], $profile);

        self::assertSame($profile, $request->targetPlatformProfile());
        self::assertSame('8.3.0', $request->targetPhp());
        self::assertSame('profile', $request->targetPhpProvenance());
        self::assertSame([['package' => 'php', 'constraint' => '8.3.0']], $request->targets()->toArray());
        self::assertSame($profile->summary(), $request->toArray()['target_platform_profile']);
    }

    public function testItAcceptsMatchingAndRejectsContradictoryPhpInputs(): void
    {
        $profile = TargetPlatformProfile::fromArray([
            'schema_version' => '1.0',
            'completeness' => 'partial',
            'packages' => ['php' => '8.3'],
        ]);

        $matching = new UpgradeRequest(__DIR__, [], null, '8.3.0', [], [], 'json', null, false, [], $profile);
        self::assertSame('8.3.0', $matching->targetPhp());
        self::assertSame('request', $matching->targetPhpProvenance());

        $uppercase = new UpgradeRequest(
            __DIR__,
            [new UpgradeTarget('PHP', '8.3')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [],
            $profile
        );
        self::assertSame('request', $uppercase->targetPhpProvenance());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contradicts the target platform profile');
        new UpgradeRequest(__DIR__, [], null, '8.2.0', [], [], 'json', null, false, [], $profile);
    }

    /** @dataProvider incompatibleProfileAssumptionProvider */
    public function testItRejectsProfileAndExtensionConflictsDuringRequestConstruction(
        TargetPlatformProfile $profile,
        ExtensionAssumption $assumption,
        string $message
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new UpgradeRequest(
            __DIR__,
            [new UpgradeTarget('vendor/package', '^2.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [$assumption],
            $profile
        );
    }

    /** @return list<array{TargetPlatformProfile, ExtensionAssumption, string}> */
    public function incompatibleProfileAssumptionProvider(): array
    {
        return [
            [
                TargetPlatformProfile::fromArray([
                    'schema_version' => '1.0',
                    'completeness' => 'partial',
                    'packages' => ['ext-json' => '8.3.0'],
                ]),
                ExtensionAssumption::fromAbsenceInput('ext-json'),
                'contradicts the target platform profile',
            ],
            [
                TargetPlatformProfile::fromArray([
                    'schema_version' => '1.0',
                    'completeness' => 'partial',
                    'packages' => ['ext-json' => false],
                ]),
                ExtensionAssumption::fromPresenceInput('ext-json'),
                'contradicts its absence',
            ],
            [
                TargetPlatformProfile::fromArray([
                    'schema_version' => '1.0',
                    'completeness' => 'complete',
                    'packages' => ['php' => '8.3.0'],
                ]),
                ExtensionAssumption::fromPresenceInput('ext-json'),
                'presence-only',
            ],
        ];
    }

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
        self::assertNull($request->toArray()['target_platform_profile']);
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
