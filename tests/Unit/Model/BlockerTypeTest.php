<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\BlockerType;
use PHPUnit\Framework\TestCase;

final class BlockerTypeTest extends TestCase
{
    public function testTheRegisteredVocabularyIsPublishedInFull(): void
    {
        self::assertSame([
            BlockerType::PHP_PLATFORM_TOO_LOW,
            BlockerType::PHP_PLATFORM_TOO_HIGH,
            BlockerType::ROOT_CONSTRAINT_CONFLICT,
            BlockerType::TRANSITIVE_PACKAGE_CONFLICT,
            BlockerType::EXTENSION_MISSING,
            BlockerType::EXTENSION_VERSION_INCOMPATIBLE,
            BlockerType::EXTENSION_VERSION_UNKNOWN,
            BlockerType::PACKAGE_NOT_FOUND,
            BlockerType::MINIMUM_STABILITY_CONFLICT,
            BlockerType::REPLACE_PROVIDE_CONFLICT,
            BlockerType::UNKNOWN_COMPOSER_FAILURE,
            BlockerType::ABANDONED_PACKAGE,
        ], BlockerType::supportedTypes());
    }

    public function testEveryRegisteredTypeRoundTripsThroughItsStringValue(): void
    {
        foreach (BlockerType::supportedTypes() as $type) {
            self::assertTrue(BlockerType::isSupported($type));
            self::assertSame($type, BlockerType::fromString($type)->value());
        }
    }

    public function testAnUnregisteredTypeIsRejectedInsteadOfSilentlyLosingItsGuidance(): void
    {
        self::assertFalse(BlockerType::isSupported('conflict'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported blocker type "conflict".');

        BlockerType::fromString('conflict');
    }

    public function testOnlyAdvisoryTypesAreExcludedFromBlockingResolution(): void
    {
        $advisory = [];
        foreach (BlockerType::supportedTypes() as $type) {
            if (!BlockerType::fromString($type)->blocksResolution()) {
                $advisory[] = $type;
            }
        }

        self::assertSame(
            [BlockerType::EXTENSION_VERSION_UNKNOWN, BlockerType::ABANDONED_PACKAGE],
            $advisory
        );
    }

    public function testAnUnregisteredTypeStillCountsAsBlocking(): void
    {
        self::assertTrue(BlockerType::blocksResolutionFor('conflict'));
        self::assertTrue((new Blocker('conflict', 'php', 'Target PHP is blocked.', 'high', ['solver-1']))->blocksResolution());
    }

    public function testBlockingResolutionForARegisteredTypeMatchesTheRegistration(): void
    {
        self::assertTrue(BlockerType::blocksResolutionFor(BlockerType::TRANSITIVE_PACKAGE_CONFLICT));
        self::assertFalse(BlockerType::blocksResolutionFor(BlockerType::ABANDONED_PACKAGE));
        self::assertFalse(BlockerType::blocksResolutionFor(BlockerType::EXTENSION_VERSION_UNKNOWN));
    }

    /** @dataProvider guidanceProvider */
    public function testGenericGuidanceIsRenderedForTheSubjectAndBlockingPackage(
        string $type,
        string $subject,
        ?string $blockingPackage,
        string $summary,
        string $firstOption,
        string $secondOption
    ): void {
        $blockerType = BlockerType::fromString($type);

        self::assertSame($summary, $blockerType->summary());
        self::assertSame([$firstOption, $secondOption], $blockerType->options($subject, $blockingPackage));
    }

    /** @return array<string, array{string, string, ?string, string, string, string}> */
    public function guidanceProvider(): array
    {
        return [
            BlockerType::PHP_PLATFORM_TOO_LOW => [
                BlockerType::PHP_PLATFORM_TOO_LOW,
                'php',
                'vendor/blocker',
                'The requested PHP platform is lower than a package requirement.',
                'Raise the target PHP version.',
                'Select a version of `vendor/blocker` compatible with the target PHP.',
            ],
            BlockerType::PHP_PLATFORM_TOO_HIGH => [
                BlockerType::PHP_PLATFORM_TOO_HIGH,
                'php',
                'vendor/blocker',
                'The requested PHP platform is higher than a package supports.',
                'Upgrade or replace `vendor/blocker` with a version that supports the target PHP.',
                'Select a supported PHP target.',
            ],
            BlockerType::ROOT_CONSTRAINT_CONFLICT => [
                BlockerType::ROOT_CONSTRAINT_CONFLICT,
                'vendor/target',
                null,
                'A root Composer constraint conflicts with the requested target.',
                'Update the root constraint for `vendor/target`.',
                'Choose a target compatible with the existing root constraint.',
            ],
            BlockerType::TRANSITIVE_PACKAGE_CONFLICT => [
                BlockerType::TRANSITIVE_PACKAGE_CONFLICT,
                'vendor/target',
                'vendor/blocker',
                'A transitive package constraint blocks the requested target.',
                'Upgrade or replace `vendor/blocker`.',
                'Choose a `vendor/target` version compatible with the transitive constraint.',
            ],
            BlockerType::EXTENSION_MISSING => [
                BlockerType::EXTENSION_MISSING,
                'ext-fixture',
                null,
                'A required PHP extension is unavailable.',
                'Install and enable `ext-fixture` for the target runtime.',
                'Choose package versions that do not require `ext-fixture`.',
            ],
            BlockerType::EXTENSION_VERSION_INCOMPATIBLE => [
                BlockerType::EXTENSION_VERSION_INCOMPATIBLE,
                'ext-fixture',
                null,
                'The modeled PHP extension version does not satisfy a package requirement.',
                'Use a target version of `ext-fixture` that satisfies the reported constraint.',
                'Choose package versions compatible with the modeled `ext-fixture` version.',
            ],
            BlockerType::EXTENSION_VERSION_UNKNOWN => [
                BlockerType::EXTENSION_VERSION_UNKNOWN,
                'ext-fixture',
                null,
                'The assumed extension is present, but its version compatibility is unknown.',
                'Repeat the analysis with an exact version for `ext-fixture`.',
                'Verify `ext-fixture` constraints on the target runtime.',
            ],
            BlockerType::PACKAGE_NOT_FOUND => [
                BlockerType::PACKAGE_NOT_FOUND,
                'vendor/target',
                null,
                'Composer could not find the requested package or version.',
                'Verify the package name, constraint, and repositories for `vendor/target`.',
                'Choose an available package version.',
            ],
            BlockerType::MINIMUM_STABILITY_CONFLICT => [
                BlockerType::MINIMUM_STABILITY_CONFLICT,
                'vendor/target',
                null,
                'The requested package does not satisfy the project minimum stability.',
                'Choose a release allowed by the project minimum stability.',
                'Explicitly allow the required stability only after reviewing the package.',
            ],
            BlockerType::REPLACE_PROVIDE_CONFLICT => [
                BlockerType::REPLACE_PROVIDE_CONFLICT,
                'vendor/target',
                'vendor/blocker',
                'Composer found conflicting replace, provide, or conflict rules.',
                'Remove or replace `vendor/blocker`.',
                'Choose versions whose replace/provide rules can coexist.',
            ],
            BlockerType::UNKNOWN_COMPOSER_FAILURE => [
                BlockerType::UNKNOWN_COMPOSER_FAILURE,
                'vendor/target',
                null,
                'Composer failed, but the blocker type could not be classified.',
                'Inspect the linked Composer evidence.',
                'Run `composer prohibits vendor/target <constraint> --tree` in an isolated copy.',
            ],
        ];
    }

    public function testAnUnnamedBlockingPackageFallsBackToAPlaceholder(): void
    {
        self::assertSame(
            [
                'Upgrade or replace `the blocking package`.',
                'Choose a `vendor/target` version compatible with the transitive constraint.',
            ],
            BlockerType::fromString(BlockerType::TRANSITIVE_PACKAGE_CONFLICT)->options('vendor/target')
        );
    }

    public function testAbandonedPackagesRequireEvidenceSpecificGuidance(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Blocker type "abandoned-package" carries evidence-specific guidance that the detecting analyzer must supply.'
        );

        BlockerType::fromString(BlockerType::ABANDONED_PACKAGE)->summary();
    }

    public function testAbandonedPackagesRefuseGenericOptions(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Blocker type "abandoned-package" carries evidence-specific guidance that the detecting analyzer must supply.'
        );

        BlockerType::fromString(BlockerType::ABANDONED_PACKAGE)->options('vendor/legacy');
    }
}
