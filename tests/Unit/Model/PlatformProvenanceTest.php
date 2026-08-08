<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\PlatformProvenance;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class PlatformProvenanceTest extends TestCase
{
    public function testItDistinguishesRequestComposerAndHostPlatformSources(): void
    {
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/laravel-app';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('php', '8.2')]);
        $project = new ProjectState(
            $projectPath,
            new ComposerJson([
                'config' => [
                    'platform' => [
                        'php' => '7.4.33',
                        'ext-json' => '8.0.0',
                    ],
                ],
            ]),
            new ComposerLock([])
        );

        $platform = (new PlatformProvenance($request, $project))->toArray();

        self::assertSame('7.4.33', $platform['current_php']['version']);
        self::assertSame('composer_config', $platform['current_php']['provenance']);
        self::assertSame('8.2.0', $platform['target_php']['version']);
        self::assertSame('request', $platform['target_php']['provenance']);
        self::assertSame('mixed', $platform['extensions']['provenance']);
        self::assertTrue($platform['extensions']['explicitly_modeled']);
        self::assertSame('partial', $platform['extensions']['completeness']);
        self::assertSame('analyzer_runtime', $platform['extensions']['unmodeled_provenance']);
        self::assertSame([[
            'name' => 'ext-json',
            'state' => 'present',
            'version' => '8.0.0',
            'provenance' => 'composer_config',
        ]], $platform['extensions']['assumptions']);
        self::assertSame([
            'Composer modeled only the listed extension assumptions; every unlisted extension still came from the analyzer runtime.',
        ], (new PlatformProvenance($request, $project))->uncertainties());
    }

    public function testItMarksAnUnconfiguredExtensionPlatformAsHostDependent(): void
    {
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/laravel-app';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('php', '8.2')]);
        $platform = new PlatformProvenance(
            $request,
            new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([]))
        );

        self::assertSame([
            'provenance' => 'analyzer_runtime',
            'explicitly_modeled' => false,
            'completeness' => 'none',
            'unmodeled_provenance' => 'analyzer_runtime',
            'assumptions' => [],
        ], $platform->toArray()['extensions']);
        self::assertNotSame([], $platform->uncertainties());
    }
}
