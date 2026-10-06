<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ComposerJson;
use PHPUnit\Framework\TestCase;

final class ComposerJsonTest extends TestCase
{
    public function testMatchingNormalizedPlatformDuplicatesCollapseIndependentlyOfOrder(): void
    {
        $first = new ComposerJson(['config' => ['platform' => [
            'EXT-JSON' => '8.3.0',
            'ext-json' => '8.3.0',
            'PHP' => '8.3.4',
            'php' => '8.3.4',
        ]]]);
        $reversed = new ComposerJson(['config' => ['platform' => [
            'php' => '8.3.4',
            'PHP' => '8.3.4',
            'ext-json' => '8.3.0',
            'EXT-JSON' => '8.3.0',
        ]]]);

        self::assertSame([
            'ext-json' => '8.3.0',
            'php' => '8.3.4',
        ], $first->configuredPlatformPackages());
        self::assertSame($first->configuredPlatformPackages(), $reversed->configuredPlatformPackages());
        self::assertSame('8.3.4', $first->platformPhp());
        self::assertSame($first->configuredExtensions(), $reversed->configuredExtensions());
    }

    public function testContradictoryNormalizedPlatformDuplicatesRejectIndependentlyOfOrder(): void
    {
        $messages = [];
        foreach ([
            ['EXT-JSON' => '8.3.0', 'ext-json' => false],
            ['ext-json' => false, 'EXT-JSON' => '8.3.0'],
        ] as $platform) {
            try {
                new ComposerJson(['config' => ['platform' => $platform]]);
                self::fail('Expected contradictory normalized config.platform names to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        self::assertSame([
            'Project config.platform contains contradictory duplicate package names.',
            'Project config.platform contains contradictory duplicate package names.',
        ], $messages);
    }

    public function testContradictoryPlatformDuplicatesAreRejectedWhenTheManifestIsConstructed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project config.platform contains contradictory duplicate package names.');

        new ComposerJson(['config' => ['platform' => ['EXT-JSON' => '8.3.0', 'ext-json' => false]]]);
    }

    public function testInvalidPlatformEntriesAreIgnored(): void
    {
        $composer = new ComposerJson(['config' => ['platform' => [
            'ext-json' => ['not', 'a', 'version'],
            'ext-intl' => '72.1',
        ]]]);

        self::assertSame(['ext-intl' => '72.1'], $composer->configuredPlatformPackages());
    }
}
