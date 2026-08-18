<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ComposerScenarioRunnerRestrictedTest extends TestCase
{
    public function testVersionMismatchStopsBeforeWorkspaceOrScenarioExecution(): void
    {
        $calls = 0;
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function () use (&$calls): array {
                ++$calls;
                throw new \LogicException('Scenario process must not run.');
            },
            static fn (): string => '1.10.27'
        );

        $result = $this->runScenario($runner, ComposerExecutionConfiguration::compatible());

        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->outcome());
        self::assertStringContainsString('does not match', $result->stderr());
        self::assertSame(0, $calls);
    }

    public function testDifferentExplicitExecutablesDoNotShareDetectedVersionCacheEntries(): void
    {
        $versionProbes = [];
        $scenarioExecutables = [];
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command) use (&$scenarioExecutables): array {
                $scenarioExecutables[] = $command[0];

                return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
            },
            static function (ComposerExecutionConfiguration $execution) use (&$versionProbes): string {
                $versionProbes[] = $execution->executable();

                return $execution->executable() === '/tools/composer-a' ? '2.8.12' : '1.10.27';
            }
        );

        $first = $this->runScenario(
            $runner,
            ComposerExecutionConfiguration::restricted('/tools/composer-a', '^2.8')
        );
        $second = $this->runScenario(
            $runner,
            ComposerExecutionConfiguration::restricted('/tools/composer-b', '^2.8')
        );

        self::assertTrue($first->succeeded());
        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $second->outcome());
        self::assertStringContainsString('does not match', $second->stderr());
        self::assertSame(['/tools/composer-a', '/tools/composer-b'], $versionProbes);
        self::assertSame(['/tools/composer-a'], $scenarioExecutables);
    }

    public function testRestrictedOfflineCacheHitUsesEmptyOwnedConfigurationAndScrubsControlledCredentialSources(): void
    {
        $canary = 'seeded-global-credential-canary';
        $seedRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'php-upgrade-preflight-restricted-seed-' . bin2hex(random_bytes(8));
        $seedHome = $seedRoot . DIRECTORY_SEPARATOR . 'composer-home';
        $seedCache = $seedRoot . DIRECTORY_SEPARATOR . 'composer-cache';
        $seedXdg = $seedRoot . DIRECTORY_SEPARATOR . 'xdg';
        foreach ([$seedHome, $seedCache, $seedXdg] as $directory) {
            mkdir($directory, 0700, true);
        }
        mkdir($seedXdg . DIRECTORY_SEPARATOR . 'composer', 0700, true);
        file_put_contents($seedHome . DIRECTORY_SEPARATOR . 'config.json', json_encode([
            'http-basic' => ['private.example.invalid' => ['username' => $canary, 'password' => $canary]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($seedHome . DIRECTORY_SEPARATOR . 'auth.json', json_encode([
            'github-oauth' => ['github.com' => $canary],
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $seedXdg . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'auth.json',
            json_encode(['bearer' => ['private.example.invalid' => $canary]], JSON_THROW_ON_ERROR)
        );
        file_put_contents($seedCache . DIRECTORY_SEPARATOR . 'credential-canary', $canary);
        file_put_contents($seedRoot . DIRECTORY_SEPARATOR . 'secret-composer.json', $canary);

        $seededEnvironment = [
            'COMPOSER' => $seedRoot . DIRECTORY_SEPARATOR . 'secret-composer.json',
            'COMPOSER_HOME' => $seedHome,
            'COMPOSER_CACHE_DIR' => $seedCache,
            'COMPOSER_AUTH' => json_encode(['bearer' => ['private.example.invalid' => $canary]], JSON_THROW_ON_ERROR),
            'XDG_CONFIG_HOME' => $seedXdg,
            'XDG_DATA_HOME' => $seedXdg,
            'XDG_CACHE_HOME' => $seedXdg,
            'HTTP_PROXY' => 'http://' . $canary . '@proxy.example.invalid',
            'HTTPS_PROXY' => 'http://' . $canary . '@proxy.example.invalid',
            'ALL_PROXY' => 'socks5://' . $canary . '@proxy.example.invalid',
            'NO_PROXY' => $canary,
            'http_proxy' => 'http://' . $canary . '@proxy.example.invalid',
            'https_proxy' => 'http://' . $canary . '@proxy.example.invalid',
            'all_proxy' => 'socks5://' . $canary . '@proxy.example.invalid',
            'no_proxy' => $canary,
            'GIT_ASKPASS' => $seedRoot . DIRECTORY_SEPARATOR . $canary . '-git-askpass',
            'SSH_ASKPASS' => $seedRoot . DIRECTORY_SEPARATOR . $canary . '-ssh-askpass',
            'GIT_TERMINAL_PROMPT' => '1',
        ];
        $previousEnvironment = $this->seedEnvironment($seededEnvironment);
        $observed = [];
        $runner = new ComposerScenarioRunner(
            null,
            null,
            function (array $command, string $directory, array $environment, int $timeout) use (&$observed, $canary, $seedRoot): array {
                $observed = compact('command', 'directory', 'environment', 'timeout');
                $process = $this->runInstrumentedComposer($directory, $environment, true);
                self::assertSame(0, $process['exit_code'], $process['stderr']);
                $child = json_decode($process['stdout'], true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($child);
                self::assertTrue($child['cache_hit']);
                self::assertTrue($child['offline_requested']);
                self::assertSame("{}\n", $child['config']);
                self::assertSame("{}\n", $child['auth']);
                self::assertSame('{}', $child['environment']['COMPOSER_AUTH']);
                self::assertFalse($child['environment']['COMPOSER']);
                self::assertSame('0', $child['environment']['GIT_TERMINAL_PROMPT']);
                foreach ([
                    'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY',
                    'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy',
                    'GIT_ASKPASS', 'SSH_ASKPASS',
                ] as $name) {
                    self::assertFalse($child['environment'][$name]);
                }
                $encoded = json_encode($child, JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString($canary, $encoded);
                self::assertStringNotContainsString($seedRoot, $encoded);

                return ['exit_code' => 0, 'stdout' => 'Instrumented offline cache hit.', 'stderr' => ''];
            },
            static fn (): string => '2.8.12'
        );
        $execution = ComposerExecutionConfiguration::restricted('composer', '^2.8', 123, 17);

        try {
            $result = $this->runScenario($runner, $execution);
        } finally {
            $this->restoreEnvironment($previousEnvironment);
            (new Filesystem())->remove($seedRoot);
        }

        self::assertTrue($result->succeeded());
        self::assertSame(123, $observed['timeout']);
        self::assertSame('1', $observed['environment']['COMPOSER_DISABLE_NETWORK']);
        self::assertSame('{}', $observed['environment']['COMPOSER_AUTH']);
        foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY', 'GIT_ASKPASS', 'SSH_ASKPASS'] as $name) {
            self::assertFalse($observed['environment'][$name]);
        }
        self::assertStringContainsString('.php-upgrade-preflight-composer', $observed['environment']['COMPOSER_HOME']);
        self::assertDirectoryDoesNotExist($observed['directory']);
    }

    public function testRestrictedOfflineMetadataMissIsOperationalUncertaintyNotASolverBlocker(): void
    {
        $runner = new ComposerScenarioRunner(
            null,
            null,
            fn (array $_command, string $directory, array $environment): array => $this->runInstrumentedComposer(
                $directory,
                $environment,
                false
            ),
            static fn (): string => '2.8.12'
        );

        $result = $this->runScenario($runner, ComposerExecutionConfiguration::restricted());

        self::assertSame(ScenarioResult::OUTCOME_REPOSITORY_METADATA_UNAVAILABLE, $result->outcome());
        self::assertTrue($result->isOperationalFailure());
        self::assertFalse($result->isSolverFailure());
        self::assertSame([], $result->diagnostics());
    }

    public function testRestrictedScenarioAndDiagnosticTimeoutsReachTheInstrumentedProcessPath(): void
    {
        $timeouts = [];
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $_directory, array $environment, int $timeout) use (&$timeouts): array {
                $timeouts[] = $timeout;
                self::assertSame('1', $environment['COMPOSER_DISABLE_NETWORK']);

                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => $command[1] === 'update'
                        ? 'Your requirements could not be resolved to an installable set of packages.'
                        : 'Offline diagnostic completed.',
                ];
            },
            static fn (): string => '2.8.12'
        );

        $result = $this->runScenario(
            $runner,
            ComposerExecutionConfiguration::restricted('composer', '^2.8', 123, 17)
        );

        self::assertTrue($result->isSolverFailure());
        self::assertSame([123, 17], $timeouts);
    }

    public function testExplicitExecutablePathAndEnvironmentValuesStayOutOfResultData(): void
    {
        $executable = 'C:\\private\\token-user\\composer.phar';
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command) use ($executable): array {
                self::assertSame($executable, $command[0]);

                return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
            },
            static fn (): string => '2.8.12'
        );
        $execution = ComposerExecutionConfiguration::restricted($executable, '^2.8');

        $result = $this->runScenario($runner, $execution);
        $canonical = $result->toArray();

        self::assertSame('[COMPOSER_EXECUTABLE]', $canonical['command'][0]);
        self::assertStringNotContainsString($executable, json_encode($canonical, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('COMPOSER_AUTH', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    public function testDeclaredPrivateRepositoryUrlsStayBehindTheProcessOutputPrivacyBoundary(): void
    {
        $repositoryUrl = 'https://private-repo.example.invalid/metadata/packages.json';
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $loaded = (new ProjectStateBuilder())->build($projectPath);
        $composerData = $loaded->composerJson()->data();
        $composerData['repositories'] = [['type' => 'composer', 'url' => $repositoryUrl]];
        $project = new ProjectState(
            $projectPath,
            new ComposerJson($composerData),
            $loaded->composerLock()
        );
        $execution = ComposerExecutionConfiguration::restricted();
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
            null,
            $execution
        );
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static fn (): array => [
                'exit_code' => 0,
                'stdout' => 'Loaded repository metadata from ' . $repositoryUrl,
                'stderr' => '',
            ],
            static fn (): string => '2.8.12'
        );

        $result = $runner->run($project, $request, new Scenario('private-repository', $request->targets()));

        self::assertStringNotContainsString($repositoryUrl, $result->stdout());
        self::assertStringContainsString('[REDACTED_URL]', $result->stdout());
    }

    public function testCompatibleModeRedactsPrivateRepositoryUrlsInheritedOutsideProjectInput(): void
    {
        $repositoryUrl = 'https://global-private.example.invalid/metadata/packages.json';
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static fn (): array => [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => 'Failed to download ' . $repositoryUrl . ' from inherited global configuration.',
            ],
            static fn (): string => '2.8.12'
        );

        $result = $this->runScenario($runner, ComposerExecutionConfiguration::compatible());

        self::assertStringNotContainsString($repositoryUrl, $result->stderr());
        self::assertStringNotContainsString('global-private.example.invalid', $result->stderr());
        self::assertStringContainsString('[REDACTED_URL]', $result->stderr());
    }

    /**
     * @param array<string, string|false> $environment
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runInstrumentedComposer(string $directory, array $environment, bool $seedCache): array
    {
        $cache = $environment['COMPOSER_CACHE_DIR'] ?? null;
        self::assertIsString($cache);
        $marker = $cache . DIRECTORY_SEPARATOR . 'instrumented-metadata-cache-hit';
        if ($seedCache) {
            file_put_contents($marker, 'cached');
        }

        $probe = <<<'PHP'
$names = [
    'COMPOSER', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'COMPOSER_AUTH', 'COMPOSER_DISABLE_NETWORK',
    'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME',
    'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY',
    'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy',
    'GIT_ASKPASS', 'SSH_ASKPASS', 'GIT_TERMINAL_PROMPT',
];
$environment = [];
foreach ($names as $name) {
    $environment[$name] = getenv($name);
}
$home = getenv('COMPOSER_HOME');
$cache = getenv('COMPOSER_CACHE_DIR');
$marker = $cache . DIRECTORY_SEPARATOR . 'instrumented-metadata-cache-hit';
$cacheHit = is_file($marker);
if (!$cacheHit) {
    if (getenv('COMPOSER_DISABLE_NETWORK') === '1') {
        fwrite(STDERR, "Your requirements could not be resolved to an installable set of packages.\n");
        fwrite(STDERR, "Network disabled, request canceled: https://global-private.example.invalid/packages.json\n");
        exit(2);
    }
    fwrite(STDERR, "Instrumented Composer would attempt network access.\n");
    exit(3);
}
echo json_encode([
    'environment' => $environment,
    'config' => file_get_contents($home . DIRECTORY_SEPARATOR . 'config.json'),
    'auth' => file_get_contents($home . DIRECTORY_SEPARATOR . 'auth.json'),
    'cache_hit' => $cacheHit,
    'offline_requested' => getenv('COMPOSER_DISABLE_NETWORK') === '1',
], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process([PHP_BINARY, '-r', $probe], $directory, $environment, null, 10);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string|false>
     */
    private function seedEnvironment(array $values): array
    {
        $previous = [];
        foreach ($values as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        return $previous;
    }

    /** @param array<string, string|false> $previous */
    private function restoreEnvironment(array $previous): void
    {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    private function runScenario(
        ComposerScenarioRunner $runner,
        ComposerExecutionConfiguration $execution
    ): ScenarioResult {
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
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
            null,
            $execution
        );

        return $runner->run($project, $request, new Scenario('restricted-test', $request->targets()));
    }
}
