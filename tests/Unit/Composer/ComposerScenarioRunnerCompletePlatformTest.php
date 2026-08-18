<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Filesystem\TemporaryWorkspaceManager;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceManager;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\TargetPlatformProfile;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class ComposerScenarioRunnerCompletePlatformTest extends TestCase
{
    public function testCompleteProfileClosesEveryDiscoveredNonToolchainPlatformPackageInWorkspace(): void
    {
        $captured = null;
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $_command, string $directory) use (&$captured): array {
                $captured = json_decode(
                    (string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic operational stop.'];
            },
            static fn (): string => '2.8.12',
            null,
            null,
            static fn (): array => [
                'composer' => '2.8.12',
                'composer-plugin-api' => '2.6.0',
                'composer-runtime-api' => '2.2.2',
                'ext-host-only' => '1.0.0',
                'lib-host-only' => '4.5.6',
                'php-64bit' => '8.3.33',
            ]
        );
        [$project, $request, $platform] = $this->context($this->completeProfile());

        $runner->run($project, $request, new Scenario('complete-platform', $request->targets(), false), $platform);

        self::assertIsArray($captured);
        self::assertSame('8.3.4', $captured['config']['platform']['php']);
        self::assertSame('8.3.0', $captured['config']['platform']['ext-json']);
        self::assertSame('73.2', $captured['config']['platform']['lib-icu']);
        self::assertFalse($captured['config']['platform']['ext-host-only']);
        self::assertFalse($captured['config']['platform']['lib-host-only']);
        self::assertFalse($captured['config']['platform']['php-64bit']);
        self::assertArrayNotHasKey('composer', $captured['config']['platform']);
        self::assertArrayNotHasKey('composer-plugin-api', $captured['config']['platform']);
        self::assertArrayNotHasKey('composer-runtime-api', $captured['config']['platform']);
    }

    /** @dataProvider unsupportedComposerProvider */
    public function testCompleteProfileStopsBeforeWorkspaceExecutionWhenComposerCapabilityIsInsufficient(?string $version): void
    {
        $processCalls = 0;
        $inventoryCalls = 0;
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function () use (&$processCalls): array {
                ++$processCalls;

                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
            static fn (): ?string => $version,
            null,
            null,
            static function () use (&$inventoryCalls): array {
                ++$inventoryCalls;

                return [];
            }
        );
        [$project, $request, $platform] = $this->context($this->completeProfile());

        $result = $runner->run($project, $request, new Scenario('complete-platform', $request->targets()), $platform);

        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->outcome());
        self::assertTrue($result->isOperationalFailure());
        self::assertStringContainsString('Composer 2.2.0 or newer', $result->stderr());
        self::assertSame(0, $processCalls);
        self::assertSame(0, $inventoryCalls);

        $baseline = $runner->run(
            $project,
            $request,
            new Scenario('baseline-validation', $request->targets(), false, false, true),
            $platform
        );
        self::assertTrue($baseline->isOperationalFailure());
        self::assertSame(0, $processCalls);
    }

    /** @return list<array{?string}> */
    public function unsupportedComposerProvider(): array
    {
        return [['2.1.14'], [null]];
    }

    public function testToolchainMismatchStopsWithoutPretendingTheComposerPlatformWasSimulated(): void
    {
        $processCalls = 0;
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function () use (&$processCalls): array {
                ++$processCalls;

                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
            static fn (): string => '2.8.12',
            null,
            null,
            static fn (): array => [
                'composer' => '2.8.11',
                'composer-plugin-api' => '2.6.0',
                'composer-runtime-api' => '2.2.2',
            ]
        );
        [$project, $request, $platform] = $this->context($this->completeProfile());

        $result = $runner->run($project, $request, new Scenario('complete-platform', $request->targets()), $platform);

        self::assertTrue($result->isOperationalFailure());
        self::assertStringContainsString('cannot be simulated safely', $result->stderr());
        self::assertSame(0, $processCalls);
    }

    public function testPlatformInventoryProbeIsSanitizedAndUsesMachineReadableOutput(): void
    {
        $commands = [];
        /** @var list<array{directory: string, is_invocation_directory: bool, has_composer_manifest: bool, environment: array<string, string|false>}> $probeStates */
        $probeStates = [];
        $processCalls = 0;
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function () use (&$processCalls): array {
                ++$processCalls;

                return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic stop.'];
            },
            null,
            null,
            static function (array $command, string $workingDirectory, array $environment) use (&$commands, &$probeStates): array {
                $commands[] = $command;
                $probeStates[] = [
                    'directory' => $workingDirectory,
                    'is_invocation_directory' => $workingDirectory === getcwd(),
                    'has_composer_manifest' => is_file($workingDirectory . DIRECTORY_SEPARATOR . 'composer.json'),
                    'environment' => $environment,
                ];
                if (in_array('--version', $command, true)) {
                    return ['exit_code' => 0, 'stdout' => 'Composer version 2.8.12', 'stderr' => ''];
                }

                return ['exit_code' => 0, 'stdout' => json_encode(['platform' => [
                    ['name' => 'composer', 'version' => '2.8.12'],
                    ['name' => 'composer-plugin-api', 'version' => '2.6.0'],
                    ['name' => 'composer-runtime-api', 'version' => '2.2.2'],
                ]], JSON_THROW_ON_ERROR), 'stderr' => ''];
            }
        );
        [$project, $request, $platform] = $this->context($this->completeProfile());

        $runner->run($project, $request, new Scenario('complete-platform', $request->targets()), $platform);

        self::assertCount(2, $commands);
        self::assertCount(2, $probeStates);
        self::assertCount(2, array_unique(array_column($probeStates, 'directory')));
        self::assertSame(1, $processCalls);
        self::assertSame([
            'composer',
            'show',
            '--platform',
            '--format=json',
            '--no-scripts',
            '--no-plugins',
            '--no-interaction',
        ], $commands[1]);
        foreach ($probeStates as $probeState) {
            self::assertFalse($probeState['is_invocation_directory']);
            self::assertFalse($probeState['has_composer_manifest']);
            self::assertSame('1', $probeState['environment']['COMPOSER_NO_INTERACTION']);
            self::assertFalse($probeState['environment']['COMPOSER']);
            self::assertSame($probeState['directory'], $probeState['environment']['COMPOSER_HOME']);
            self::assertDirectoryDoesNotExist($probeState['directory']);
        }
    }

    /**
     * @dataProvider malformedPlatformInventoryProvider
     * @param array<mixed> $malformedRow
     */
    public function testMalformedPlatformInventoryStopsBeforeWorkspaceOrScenarioExecution(array $malformedRow): void
    {
        $workspaces = new ProbeOnlyWorkspaceManager();
        $processCalls = 0;
        $runner = new ComposerScenarioRunner(
            $workspaces,
            null,
            static function () use (&$processCalls): array {
                ++$processCalls;

                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
            null,
            null,
            static function (array $command, string $_workingDirectory, array $_environment) use ($malformedRow): array {
                if (in_array('--version', $command, true)) {
                    return ['exit_code' => 0, 'stdout' => 'Composer version 2.8.12', 'stderr' => ''];
                }

                return [
                    'exit_code' => 0,
                    'stdout' => json_encode(['platform' => [
                        ['name' => 'composer', 'version' => '2.8.12'],
                        ['name' => 'composer-plugin-api', 'version' => '2.6.0'],
                        ['name' => 'composer-runtime-api', 'version' => '2.2.2'],
                        $malformedRow,
                    ]], JSON_THROW_ON_ERROR),
                    'stderr' => '',
                ];
            }
        );
        [$project, $request, $platform] = $this->context($this->completeProfile());

        $result = $runner->run(
            $project,
            $request,
            new Scenario('complete-platform', $request->targets()),
            $platform
        );

        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->outcome());
        self::assertTrue($result->isOperationalFailure());
        self::assertStringContainsString('platform inventory could not be determined', $result->stderr());
        self::assertSame(0, $processCalls);
        self::assertSame(0, $workspaces->createCalls);
        self::assertNotSame([], $workspaces->removedPaths, 'Metadata probes must be cleaned up through the workspace manager.');
        foreach ($workspaces->removedPaths as $removedPath) {
            self::assertStringContainsString('php-upgrade-preflight-composer-probe-', $removedPath);
            self::assertDirectoryDoesNotExist($removedPath);
        }
    }

    /** @return array<string, array{array<mixed>}> */
    public function malformedPlatformInventoryProvider(): array
    {
        return [
            'missing name' => [[
                'version' => '1.0.0',
            ]],
            'non-string name' => [[
                'name' => 123,
                'version' => '1.0.0',
            ]],
            'recognizable extension missing version' => [[
                'name' => 'ext-host-only',
            ]],
            'recognizable library has non-string version' => [[
                'name' => 'lib-host-only',
                'version' => 456,
            ]],
        ];
    }

    /** @dataProvider unreadablePlatformInventoryProvider */
    public function testUnreadablePlatformInventoryIsCachedAsAnOperationalStop(
        int $exitCode,
        string $stdout
    ): void {
        $probeCalls = 0;
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (): array {
                throw new \LogicException('Scenario execution must not start without a platform inventory.');
            },
            null,
            null,
            static function (array $command) use ($exitCode, $stdout, &$probeCalls): array {
                ++$probeCalls;
                if (in_array('--version', $command, true)) {
                    return ['exit_code' => 0, 'stdout' => 'Composer version 2.8.12', 'stderr' => ''];
                }

                return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => 'inventory failure'];
            }
        );
        [$project, $request, $platform] = $this->context($this->completeProfile());
        $scenario = new Scenario('unreadable-platform', $request->targets(), false);

        $first = $runner->run($project, $request, $scenario, $platform);
        $second = $runner->run($project, $request, $scenario, $platform);

        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $first->outcome());
        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $second->outcome());
        self::assertSame(2, $probeCalls, 'Version and inventory should each be probed once, then cached.');
    }

    /** @return array<string, array{int, string}> */
    public function unreadablePlatformInventoryProvider(): array
    {
        return [
            'command failure' => [1, ''],
            'invalid JSON' => [0, '{'],
            'non-object JSON' => [0, 'false'],
            'missing inventory collection' => [0, '{"other":[]}'],
        ];
    }

    /** @dataProvider invalidInjectedPlatformResolverProvider */
    public function testInvalidInjectedPlatformResolverResultsStopBeforeExecution(callable $resolver): void
    {
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (): array {
                throw new \LogicException('Scenario execution must not start without a valid platform inventory.');
            },
            static fn (): string => '2.8.12',
            null,
            null,
            $resolver
        );
        [$project, $request, $platform] = $this->context($this->completeProfile());

        $result = $runner->run(
            $project,
            $request,
            new Scenario('invalid-injected-platform', $request->targets(), false),
            $platform
        );

        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->outcome());
    }

    /** @return array<string, array{callable(): mixed}> */
    public function invalidInjectedPlatformResolverProvider(): array
    {
        return [
            'invalid entry types' => [static fn (): array => [0 => '2.8.12']],
            'resolver exception' => [static function (): array {
                throw new \RuntimeException('Inventory unavailable.');
            }],
        ];
    }

    /** @return array{0: \PhpUpgradePreflight\Core\Model\ProjectState, 1: UpgradeRequest, 2: TargetPlatform} */
    private function context(TargetPlatformProfile $profile): array
    {
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('fixture/dependency', '^2.0')],
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

        return [$project, $request, TargetPlatform::fromRequest($request, $project)];
    }

    private function completeProfile(): TargetPlatformProfile
    {
        return TargetPlatformProfile::fromArray([
            'schema_version' => '1.0',
            'completeness' => 'complete',
            'packages' => [
                'php' => '8.3.4',
                'ext-json' => '8.3.0',
                'lib-icu' => '73.2',
                'composer' => '2.8.12',
                'composer-plugin-api' => '2.6.0',
                'composer-runtime-api' => '2.2.2',
            ],
        ]);
    }
}

/**
 * Accepts the metadata-probe cleanup the runner routes through the injected
 * workspace manager, while still proving that no scenario workspace was created.
 */
final class ProbeOnlyWorkspaceManager implements WorkspaceManager
{
    public int $createCalls = 0;
    /** @var list<string> */
    public array $removedPaths = [];

    private TemporaryWorkspaceManager $delegate;

    public function __construct()
    {
        $this->delegate = new TemporaryWorkspaceManager();
    }

    public function createFromProject(string $projectPath): string
    {
        ++$this->createCalls;

        throw new \LogicException('A scenario workspace must not be created.');
    }

    public function remove(string $path): void
    {
        $this->delegate->remove($path);
        $this->removedPaths[] = $path;
    }
}
