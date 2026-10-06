<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PHPUnit\Framework\TestCase;

final class ComposerExecutionConfigurationTest extends TestCase
{
    public function testCompatibleAndRestrictedPoliciesExposeOnlySafeDeterministicMetadata(): void
    {
        $compatible = ComposerExecutionConfiguration::compatible();
        $restricted = ComposerExecutionConfiguration::restricted('C:\\private\\composer.phar', '^2.8', 120, 30);
        $otherExecutable = ComposerExecutionConfiguration::restricted('C:\\other\\composer.phar', '^2.8', 120, 30);

        self::assertSame('inherited', $compatible->environmentMode());
        self::assertSame('inherited', $compatible->networkPolicy());
        self::assertFalse($compatible->isRestricted());
        self::assertTrue($restricted->isRestricted());
        self::assertSame('sanitized', $restricted->environmentMode());
        self::assertSame('best_effort_offline', $restricted->networkPolicy());
        self::assertSame('explicit', $restricted->fingerprintData()['executable_selection']);
        self::assertArrayNotHasKey('executable', $restricted->fingerprintData());
        self::assertSame($restricted->fingerprintData(), $otherExecutable->fingerprintData());
        self::assertNotSame($restricted->runtimeCacheKey(), $otherExecutable->runtimeCacheKey());
        self::assertNotSame($restricted->stateFingerprintData(), $otherExecutable->stateFingerprintData());
        self::assertStringNotContainsString(
            'C:\\private\\composer.phar',
            json_encode($restricted->stateFingerprintData(), JSON_THROW_ON_ERROR)
        );
        self::assertTrue($restricted->matchesVersion('2.8.12'));
        self::assertFalse($restricted->matchesVersion('2.7.9'));
        self::assertNull($restricted->matchesVersion(null));

        $capped = $restricted->withScenarioTimeoutSeconds(45);
        self::assertSame(120, $restricted->scenarioTimeoutSeconds());
        self::assertSame(45, $capped->scenarioTimeoutSeconds());
        self::assertSame($restricted->executable(), $capped->executable());
        self::assertSame($restricted->diagnosticTimeoutSeconds(), $capped->diagnosticTimeoutSeconds());
        self::assertSame($restricted->mode(), $capped->mode());
    }

    /** @dataProvider invalidConfigurationProvider */
    public function testItRejectsInvalidOrContradictoryConfiguration(callable $create): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $create();
    }

    /** @return array<string, array{callable(): ComposerExecutionConfiguration}> */
    public function invalidConfigurationProvider(): array
    {
        return [
            'empty executable' => [static fn () => new ComposerExecutionConfiguration('')],
            'invalid constraint' => [static fn () => new ComposerExecutionConfiguration('composer', '[')],
            'invalid mode' => [static fn () => new ComposerExecutionConfiguration('composer', '^2', 300, 60, 'unsafe')],
            'zero scenario timeout' => [static fn () => new ComposerExecutionConfiguration('composer', '^2', 0)],
            'excessive diagnostic timeout' => [static fn () => new ComposerExecutionConfiguration('composer', '^2', 300, 901)],
            'restricted inherited environment' => [static fn () => new ComposerExecutionConfiguration(
                'composer',
                '^2',
                300,
                60,
                ComposerExecutionConfiguration::MODE_RESTRICTED,
                ComposerExecutionConfiguration::ENVIRONMENT_INHERITED
            )],
        ];
    }
}
