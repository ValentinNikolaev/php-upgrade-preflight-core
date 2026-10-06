<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Filesystem\TemporaryWorkspaceManager;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceManager;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceCleanupException;
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\ExtensionAssumption;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Support\PathExposurePolicy;
use PhpUpgradePreflight\Tests\Support\FixtureSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ComposerScenarioRunnerTest extends TestCase
{
    public function testProductionProcessAdaptersRunLocalComposerMetadataAndValidation(): void
    {
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);
        $scenario = new Scenario('baseline-validation', $request->targets(), false, false, true);

        $result = (new ComposerScenarioRunner())->run($project, $request, $scenario);

        self::assertNotNull($result->composerVersion());
        self::assertSame('composer', $result->command()[0]);
        self::assertSame('validate', $result->command()[1]);
    }

    public function testWorkspaceSeedFailureIsReportedAndTheDebugPathIsRetained(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'php-upgrade-preflight-seed-failure-');
        self::assertIsString($path);
        $workspaces = new class ($path) implements WorkspaceManager {
            private string $path;

            public function __construct(string $path)
            {
                $this->path = $path;
            }

            public function createFromProject(string $projectPath): string
            {
                return $this->path;
            }

            public function remove(string $path): void
            {
            }
        };
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
            true
        );
        $runner = new ComposerScenarioRunner(
            $workspaces,
            null,
            static function (): array {
                throw new \LogicException('Process execution must not follow a seed failure.');
            },
            static fn (): string => '2.8.12'
        );

        try {
            $result = $runner->run($project, $request, new Scenario('seed-failure', $request->targets()));

            self::assertSame(ScenarioResult::OUTCOME_WORKSPACE_FAILURE, $result->outcome());
            self::assertStringContainsString('Unable to seed the temporary composer.json.', $result->stderr());
            self::assertSame($path, $result->tempPath());
        } finally {
            @unlink($path);
        }
    }

    /** @dataProvider unavailableComposerVersionProvider */
    public function testComposerVersionDetectionRejectsFailedOrUnparseableMetadata(int $exitCode, string $output): void
    {
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static fn (): array => ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic stop.'],
            null,
            null,
            static fn (): array => ['exit_code' => $exitCode, 'stdout' => $output, 'stderr' => '']
        );

        self::assertNull($this->runFixtureScenario($runner)->composerVersion());
    }

    /** @return array<string, array{int, string}> */
    public function unavailableComposerVersionProvider(): array
    {
        return [
            'metadata command failed' => [1, ''],
            'metadata output was unparseable' => [0, 'version unavailable'],
        ];
    }

    public function testItCapturesExecutionMetadataAndCandidateLockEvidence(): void
    {
        $lockContents = str_replace("\n", "\r\n", json_encode([
            'content-hash' => 'fixture-content-hash',
            'packages' => [['name' => 'fixture/dependency', 'version' => '2.0.0']],
            'packages-dev' => [['name' => 'fixture/dev-dependency', 'version' => '1.0.0']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $clockValues = [100.0, 100.125];
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $directory) use ($lockContents): array {
                file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', $lockContents);

                return [
                    'exit_code' => 0,
                    'stdout' => 'Resolved candidate.',
                    'stderr' => 'Diagnostic note.',
                ];
            },
            static fn (): string => '2.8.12',
            static function () use (&$clockValues): float {
                $value = array_shift($clockValues);
                if (!is_float($value)) {
                    throw new \LogicException('No test clock value remains.');
                }

                return $value;
            }
        );
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        $result = $runner->run((new ProjectStateBuilder())->build($projectPath), $request, new Scenario('exact-target', $request->targets(), false));

        self::assertSame('2.8.12', $result->composerVersion());
        self::assertSame([
            'composer',
            'update',
            'fixture/dependency',
            '--no-scripts',
            '--no-plugins',
            '--no-install',
            '--no-audit',
            '--no-progress',
            '--no-interaction',
        ], $result->command());
        self::assertSame(125, $result->durationMs());
        self::assertSame(0, $result->exitCode());
        self::assertSame('Resolved candidate.', $result->stdout());
        self::assertSame('Diagnostic note.', $result->stderr());
        self::assertNotNull($result->candidateLockEvidence());
        $canonicalLockContents = str_replace(["\r\n", "\r"], "\n", $lockContents);
        self::assertSame(hash('sha256', $canonicalLockContents), $result->candidateLockEvidence()->sha256());
        self::assertSame('fixture-content-hash', $result->candidateLockEvidence()->contentHash());
        self::assertSame(2, $result->candidateLockEvidence()->packageCount());
        $lock = $result->lock();
        self::assertNotNull($lock);
        $directPackage = $lock->package('fixture/dependency');
        $transitivePackage = $lock->package('fixture/dev-dependency');
        self::assertNotNull($directPackage);
        self::assertNotNull($transitivePackage);
        self::assertTrue($directPackage->isDirect());
        self::assertFalse($transitivePackage->isDirect());
    }

    public function testDurationIncludesTargetDiagnosticsAndWorkspaceCleanup(): void
    {
        $now = 100.0;
        $workspaces = new ClockAdvancingWorkspaceManager(static function () use (&$now): void {
            $now += 0.250;
        });
        $runner = new ComposerScenarioRunner(
            $workspaces,
            null,
            static function (array $command) use (&$now): array {
                if (in_array('prohibits', $command, true)) {
                    $now += 0.375;

                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic target diagnostic.'];
                }
                $now += 0.125;

                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
                ];
            },
            static fn (): string => '2.8.12',
            static function () use (&$now): float {
                return $now;
            }
        );
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        try {
            $result = $runner->run(
                (new ProjectStateBuilder())->build($projectPath),
                $request,
                new Scenario('exact-target', $request->targets(), false)
            );

            self::assertSame(750, $result->durationMs());
            self::assertCount(1, $result->diagnostics());
            self::assertCount(1, $workspaces->removedPaths);
        } finally {
            $workspaces->forceCleanup();
        }
    }

    public function testBaselineValidationUsesTheUnchangedManifestAndValidationCommand(): void
    {
        $capturedCommand = null;
        $capturedComposer = null;
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory) use (&$capturedCommand, &$capturedComposer): array {
            $capturedCommand = $command;
            $capturedComposer = json_decode((string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'), true, 512, JSON_THROW_ON_ERROR);

            return ['exit_code' => 0, 'stdout' => 'Valid.', 'stderr' => ''];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], null, '8.1');
        $scenario = new Scenario('baseline-validation', $request->targets(), false, false, true);

        $result = $runner->run((new ProjectStateBuilder())->build($projectPath), $request, $scenario);

        self::assertTrue($result->succeeded());
        self::assertSame(
            ['composer', 'validate', '--check-lock', '--no-check-publish', '--no-scripts', '--no-plugins', '--no-interaction'],
            $capturedCommand
        );
        self::assertIsArray($capturedComposer);
        self::assertSame('1.0.0', $capturedComposer['require']['fixture/dependency']);
        self::assertArrayNotHasKey('config', $capturedComposer);
    }

    public function testVersionProbeDisablesScriptsAndPlugins(): void
    {
        $capturedCommand = null;
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $directory): array {
                return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic operational stop.'];
            },
            null,
            null,
            static function (array $command, string $_workingDirectory, array $_environment) use (&$capturedCommand): array {
                $capturedCommand = $command;

                return ['exit_code' => 0, 'stdout' => 'Composer version 2.8.12', 'stderr' => ''];
            }
        );

        $result = $this->runFixtureScenario($runner);

        self::assertSame('2.8.12', $result->composerVersion());
        self::assertSame([
            'composer',
            '--version',
            '--no-ansi',
            '--no-scripts',
            '--no-plugins',
            '--no-interaction',
        ], $capturedCommand);
    }

    public function testVersionProbeRemovesItsIsolatedDirectoryWhenTheProcessRunnerThrows(): void
    {
        $probeDirectory = null;
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static fn (): array => [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Synthetic operational stop.',
            ],
            null,
            null,
            static function (array $_command, string $workingDirectory, array $_environment) use (&$probeDirectory): array {
                $probeDirectory = $workingDirectory;

                throw new \RuntimeException('Synthetic probe failure.');
            }
        );

        $result = $this->runFixtureScenario($runner);

        self::assertNull($result->composerVersion());
        self::assertNotNull($probeDirectory);
        self::assertDirectoryDoesNotExist($probeDirectory);
    }

    public function testItUpdatesTheLockWithoutInstallingDependencies(): void
    {
        $capturedCommand = null;
        $runner = new ComposerScenarioRunner(null, null, static function (array $command) use (&$capturedCommand): array {
            $capturedCommand = $command;

            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic operational stop.'];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        $runner->run((new ProjectStateBuilder())->build($projectPath), $request, new Scenario('test', $request->targets()));

        self::assertIsArray($capturedCommand);
        self::assertContains('--no-install', $capturedCommand);
        self::assertContains('--no-audit', $capturedCommand);
        self::assertContains('--no-progress', $capturedCommand);
    }

    public function testItUpdatesAnExistingDevRequirementWithoutDuplicatingItInRequire(): void
    {
        $captured = null;
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory) use (&$captured): array {
            $captured = json_decode((string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'), true, 512, JSON_THROW_ON_ERROR);
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', json_encode([
                'packages' => [],
                'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '10.5.0']],
            ], JSON_THROW_ON_ERROR));

            return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
        });
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('phpunit/phpunit', '^10.0')]);

        $result = $runner->run((new ProjectStateBuilder())->build($projectPath), $request, new Scenario('test', $request->targets()));

        self::assertIsArray($captured);
        self::assertSame('^10.0', $captured['require-dev']['phpunit/phpunit']);
        self::assertArrayNotHasKey('phpunit/phpunit', $captured['require']);
        $lock = $result->lock();
        self::assertNotNull($lock);
        $package = $lock->package('phpunit/phpunit');
        self::assertNotNull($package);
        self::assertTrue($package->isDirect());
    }

    public function testItAppliesExtensionAssumptionsOnlyInsideTheTemporaryWorkspace(): void
    {
        $capturedComposer = null;
        $capturedCommand = null;
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $directory) use (&$capturedComposer, &$capturedCommand): array {
                $capturedCommand = $command;
                $capturedComposer = json_decode(
                    (string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic operational stop.'];
            },
            static fn (): string => '2.2.0'
        );
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $composerPath = $projectPath . DIRECTORY_SEPARATOR . 'composer.json';
        $originalComposer = file_get_contents($composerPath);
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            '8.2',
            [],
            [],
            'json',
            null,
            false,
            [
                ExtensionAssumption::fromPresenceInput('ext-intl:72.1'),
                ExtensionAssumption::fromPresenceInput('ext-json'),
                ExtensionAssumption::fromAbsenceInput('ext-xdebug'),
            ]
        );

        $runner->run((new ProjectStateBuilder())->build($projectPath), $request, new Scenario('test', $request->targets()));

        self::assertIsArray($capturedComposer);
        self::assertSame('72.1', $capturedComposer['config']['platform']['ext-intl']);
        self::assertFalse($capturedComposer['config']['platform']['ext-xdebug']);
        self::assertSame('0', $capturedComposer['config']['platform']['ext-json']);
        self::assertIsArray($capturedCommand);
        self::assertNotContains('--ignore-platform-req=ext-json', $capturedCommand);
        self::assertSame($originalComposer, file_get_contents($composerPath));
    }

    public function testItRebasesRelativePathRepositoriesAgainstTheOriginalProject(): void
    {
        $capturedUrl = null;
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory) use (&$capturedUrl): array {
            $composer = json_decode((string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $capturedUrl = $composer['repositories'][1]['url'];

            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic operational stop.'];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'path-repository' . DIRECTORY_SEPARATOR . 'project';
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^1.0')]);

        $runner->run((new ProjectStateBuilder())->build($projectPath), $request, new Scenario('test', $request->targets()));

        self::assertIsString($capturedUrl);
        self::assertTrue(Path::isAbsolute($capturedUrl));
        self::assertSame(
            Path::canonicalize(dirname($projectPath) . DIRECTORY_SEPARATOR . 'repository' . DIRECTORY_SEPARATOR . '*'),
            Path::canonicalize($capturedUrl)
        );
    }

    public function testItPreservesEnvironmentVariablesInPathRepositories(): void
    {
        foreach (['$HOME/git/pkg', '${HOME}/git/pkg', '%USERPROFILE%/git/pkg', '~/git/pkg'] as $url) {
            $projectPath = $this->createProjectWithPathRepository($url);
            $capturedUrl = null;
            $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory) use (&$capturedUrl, $url): array {
                $composer = json_decode((string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'), true, 512, JSON_THROW_ON_ERROR);
                $capturedUrl = $composer['repositories'][0]['url'];

                return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Repository root: ' . $url];
            });
            $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^1.0')]);

            try {
                $result = $runner->run(
                    (new ProjectStateBuilder())->build($projectPath),
                    $request,
                    new Scenario('test', $request->targets())
                );

                self::assertSame($url, $capturedUrl);
                self::assertStringContainsString(PathExposurePolicy::LOCAL_REPOSITORY, $result->stderr());
                self::assertStringNotContainsString($url, $result->stderr());
            } finally {
                (new Filesystem())->remove($projectPath);
            }
        }
    }

    public function testItRedactsArtifactAndFileRepositoryRoots(): void
    {
        $artifactProject = $this->createProjectWithPathRepository('../private-artifacts', 'artifact');
        $artifactRoot = Path::makeAbsolute('../private-artifacts', $artifactProject);
        $artifactRunner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 1,
            'stdout' => 'Artifact repository: ' . $artifactRoot,
            'stderr' => 'Synthetic operational stop.',
        ]);
        $artifactRequest = new UpgradeRequest(
            $artifactProject,
            [new UpgradeTarget('fixture/dependency', '^1.0')]
        );

        try {
            $artifactResult = $artifactRunner->run(
                (new ProjectStateBuilder())->build($artifactProject),
                $artifactRequest,
                new Scenario('artifact-repository', $artifactRequest->targets())
            );

            self::assertStringContainsString(PathExposurePolicy::LOCAL_REPOSITORY, $artifactResult->stdout());
            self::assertStringNotContainsString($artifactRoot, $artifactResult->stdout());
        } finally {
            (new Filesystem())->remove($artifactProject);
        }

        $fileRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'private-file-repository';
        $normalizedFileRoot = str_replace('\\', '/', $fileRoot);
        $fileUrl = preg_match('/^[A-Za-z]:\//', $normalizedFileRoot) === 1
            ? 'file:///' . $normalizedFileRoot
            : 'file://' . $normalizedFileRoot;
        $fileProject = $this->createProjectWithPathRepository($fileUrl, 'composer');
        $fileRunner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 1,
            'stdout' => 'File repository: ' . $fileUrl . ' at ' . $fileRoot,
            'stderr' => 'Synthetic operational stop.',
        ]);
        $fileRequest = new UpgradeRequest($fileProject, [new UpgradeTarget('fixture/dependency', '^1.0')]);

        try {
            $fileResult = $fileRunner->run(
                (new ProjectStateBuilder())->build($fileProject),
                $fileRequest,
                new Scenario('file-repository', $fileRequest->targets())
            );

            self::assertSame(2, substr_count($fileResult->stdout(), PathExposurePolicy::LOCAL_REPOSITORY));
            self::assertStringNotContainsString($fileUrl, $fileResult->stdout());
            self::assertStringNotContainsString($fileRoot, $fileResult->stdout());
        } finally {
            (new Filesystem())->remove($fileProject);
        }

        $vcsRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'private-vcs-repository';
        $vcsProject = $this->createProjectWithPathRepository($vcsRoot, 'vcs');
        $vcsRunner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 1,
            'stdout' => 'VCS repository: ' . $vcsRoot,
            'stderr' => 'Synthetic operational stop.',
        ]);
        $vcsRequest = new UpgradeRequest($vcsProject, [new UpgradeTarget('fixture/dependency', '^1.0')]);

        try {
            $vcsResult = $vcsRunner->run(
                (new ProjectStateBuilder())->build($vcsProject),
                $vcsRequest,
                new Scenario('vcs-repository', $vcsRequest->targets())
            );

            self::assertStringContainsString(PathExposurePolicy::LOCAL_REPOSITORY, $vcsResult->stdout());
            self::assertStringNotContainsString($vcsRoot, $vcsResult->stdout());
        } finally {
            (new Filesystem())->remove($vcsProject);
        }
    }

    public function testItSeparatesSolverAndOperationalFailures(): void
    {
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);
        $scenario = new Scenario('test', $request->targets());

        $solverRunner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 2,
            'stdout' => '',
            'stderr' => "Your requirements could not be resolved to an installable set of packages.\n- Root composer.json requires fixture/dependency ^2.0.",
        ]);
        $operationalRunner = new ComposerScenarioRunner(null, null, static function (): array {
            throw new \RuntimeException('Composer executable was unavailable.');
        });

        $solverResult = $solverRunner->run($project, $request, $scenario);
        $operationalResult = $operationalRunner->run($project, $request, $scenario);

        self::assertSame(ScenarioResult::FAILURE_SOLVER, $solverResult->failureType());
        self::assertSame(ScenarioResult::OUTCOME_SOLVER_FAILURE, $solverResult->outcome());
        self::assertSame(ScenarioResult::FAILURE_OPERATIONAL, $operationalResult->failureType());
        self::assertSame(ScenarioResult::OUTCOME_COMPOSER_MISSING, $operationalResult->outcome());
    }

    public function testMissingComposerIsAStructuredOutcome(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'composer: command not found',
        ]);

        $result = $this->runFixtureScenario($runner);

        self::assertSame(ScenarioResult::OUTCOME_COMPOSER_MISSING, $result->outcome());
        self::assertSame(ScenarioResult::OUTCOME_COMPOSER_MISSING, $result->toArray()['outcome']);
        self::assertTrue($result->isOperationalFailure());
    }

    public function testTimeoutIsAStructuredOutcome(): void
    {
        $snapshot = FixtureSnapshot::capture($this->fixtureProjectPath());
        $process = new Process(['composer', 'update']);
        $process->setTimeout(300);
        $runner = new ComposerScenarioRunner(null, null, static function () use ($process): array {
            throw new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
        });

        $result = $this->runFixtureScenario($runner);

        self::assertSame(ScenarioResult::OUTCOME_TIMEOUT, $result->outcome());
        self::assertTrue($result->isOperationalFailure());
        self::assertStringContainsString('exceeded the timeout', $result->stderr());
        $snapshot->assertUnchanged($this);
    }

    public function testInvalidCandidateLockJsonIsAStructuredOutcome(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory): array {
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', '{invalid');

            return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
        });

        $result = $this->runFixtureScenario($runner);

        self::assertSame(ScenarioResult::OUTCOME_INVALID_JSON, $result->outcome());
        self::assertTrue($result->isOperationalFailure());
        self::assertStringContainsString('Invalid JSON', $result->stderr());
    }

    public function testMissingCandidateLockfileIsAStructuredOutcome(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory): array {
            unlink($directory . DIRECTORY_SEPARATOR . 'composer.lock');

            return ['exit_code' => 0, 'stdout' => 'Resolved without a lock.', 'stderr' => ''];
        });

        $result = $this->runFixtureScenario($runner);

        self::assertSame(ScenarioResult::OUTCOME_LOCKFILE_MISSING, $result->outcome());
        self::assertTrue($result->isOperationalFailure());
        self::assertSame(0, $result->exitCode());
    }

    public function testUnexpectedNonZeroExitIsAStructuredProcessFailure(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'Transport failed before dependency resolution.',
        ]);

        $result = $this->runFixtureScenario($runner);

        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->outcome());
        self::assertTrue($result->isOperationalFailure());
    }

    public function testSolverFailureRunsUsefulProhibitsDiagnosticsInTheScenarioWorkspace(): void
    {
        $calls = [];
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory) use (&$calls): array {
            $calls[] = ['command' => $command, 'directory' => $directory];

            if ($command[1] === 'prohibits') {
                return [
                    'exit_code' => 0,
                    'stdout' => 'fixture/blocker 1.0.0 requires fixture/dependency (^1.0)',
                    'stderr' => '',
                ];
            }

            return [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
            ];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')], '8.0', '8.1');

        $result = $runner->run($project, $request, new Scenario('exact-target', $request->targets(), false));

        self::assertSame(ScenarioResult::FAILURE_SOLVER, $result->failureType());
        self::assertCount(2, $result->diagnostics());
        self::assertSame($calls[0]['directory'], $calls[1]['directory']);
        self::assertSame($calls[0]['directory'], $calls[2]['directory']);
        self::assertSame([
            'composer',
            'prohibits',
            'fixture/dependency',
            '^2.0',
            '--tree',
            '--locked',
            '--no-scripts',
            '--no-plugins',
            '--no-interaction',
        ], $calls[1]['command']);
        self::assertSame('php', $result->diagnostics()[1]->package());
        self::assertStringContainsString('fixture/blocker', $result->diagnostics()[0]->stdout());
    }

    public function testDiagnosticsSkipSatisfiedLockedTargetsAndStagedCurrentPhp(): void
    {
        $calls = [];
        $runner = new ComposerScenarioRunner(null, null, static function (array $command) use (&$calls): array {
            $calls[] = $command;

            return [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
            ];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^1.0')], '8.0', '8.1');
        $stagedTargets = new \PhpUpgradePreflight\Core\Model\UpgradeTargetSet(
            [new UpgradeTarget('fixture/dependency', '^1.0')],
            '8.0'
        );

        $result = $runner->run($project, $request, new Scenario('staged-targets', $stagedTargets));

        self::assertSame([], $result->diagnostics());
        self::assertCount(1, $calls);
    }

    public function testPresenceOnlyAssumptionsRemainDeterministicDuringDiagnostics(): void
    {
        $calls = [];
        $diagnosticPlatform = null;
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory) use (&$calls, &$diagnosticPlatform): array {
            $calls[] = $command;

            if ($command[1] === 'prohibits') {
                $composer = json_decode(
                    (string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $diagnosticPlatform = $composer['config']['platform']['ext-json'] ?? null;
            }

            return [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
            ];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
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
            [ExtensionAssumption::fromPresenceInput('ext-json')]
        );

        $result = $runner->run($project, $request, new Scenario('exact-target', $request->targets()));

        self::assertSame(ScenarioResult::FAILURE_SOLVER, $result->failureType());
        self::assertCount(1, $result->diagnostics());
        self::assertCount(2, $calls);
        self::assertSame('0', $diagnosticPlatform);
    }

    public function testDiagnosticFailureDoesNotReplaceThePrimarySolverOutcome(): void
    {
        $runner = new ComposerScenarioRunner(null, null, static function (array $command): array {
            if ($command[1] === 'prohibits') {
                throw new \RuntimeException('Diagnostic process could not start.');
            }

            return [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
            ];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        $result = $runner->run($project, $request, new Scenario('exact-target', $request->targets()));

        self::assertSame(ScenarioResult::FAILURE_SOLVER, $result->failureType());
        self::assertSame(2, $result->exitCode());
        self::assertCount(1, $result->diagnostics());
        self::assertSame(1, $result->diagnostics()[0]->exitCode());
        self::assertStringContainsString('could not start', $result->diagnostics()[0]->stderr());
    }

    public function testComposerBefore24RecordsUnsupportedLockedDiagnosticWithoutRunningIt(): void
    {
        $calls = [];
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command) use (&$calls): array {
                $calls[] = $command;

                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
                ];
            },
            static fn (): string => '2.3.10'
        );
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        $result = $runner->run($project, $request, new Scenario('exact-target', $request->targets()));

        self::assertSame(ScenarioResult::FAILURE_SOLVER, $result->failureType());
        self::assertCount(1, $calls);
        self::assertCount(1, $result->diagnostics());
        self::assertSame([], $result->diagnostics()[0]->command());
        self::assertStringContainsString('Composer 2.4.0 or newer is required', $result->diagnostics()[0]->stderr());
        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->diagnostics()[0]->outcome());
    }

    public function testComposerBefore22RejectsAbsentExtensionSimulationBeforeCreatingAWorkspace(): void
    {
        $workspaceManager = new FailingCreateWorkspaceManager();
        $processCalls = 0;
        $runner = new ComposerScenarioRunner(
            $workspaceManager,
            null,
            static function () use (&$processCalls): array {
                ++$processCalls;

                throw new \LogicException('The process must not run for an unsupported absence simulation.');
            },
            static fn (): string => '2.1.14'
        );
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
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
            [ExtensionAssumption::fromAbsenceInput('ext-xdebug')]
        );

        $result = $runner->run($project, $request, new Scenario('exact-target', $request->targets()));

        self::assertSame(ScenarioResult::FAILURE_OPERATIONAL, $result->failureType());
        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->outcome());
        self::assertSame('2.1.14', $result->composerVersion());
        self::assertStringContainsString('Composer 2.2.0 or newer is required', $result->stderr());
        self::assertSame(0, $processCalls);
        self::assertSame(0, $workspaceManager->createCalls);
        self::assertSame(0, $workspaceManager->removeCalls);
    }

    public function testItSeparatesBaselineValidationAndOperationalFailures(): void
    {
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);
        $scenario = new Scenario('baseline-validation', $request->targets(), false, false, true);
        $validationRunner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 2,
            'stdout' => '',
            'stderr' => 'The lock file is not up to date with the latest changes in composer.json.',
        ]);
        $operationalRunner = new ComposerScenarioRunner(null, null, static function (): array {
            throw new \RuntimeException('Composer executable was unavailable.');
        });

        $validationResult = $validationRunner->run($project, $request, $scenario);
        $operationalResult = $operationalRunner->run($project, $request, $scenario);

        self::assertSame(ScenarioResult::FAILURE_VALIDATION, $validationResult->failureType());
        self::assertSame(ScenarioResult::OUTCOME_VALIDATION_FAILURE, $validationResult->outcome());
        self::assertTrue($validationResult->isValidationFailure());
        self::assertFalse($validationResult->isOperationalFailure());
        self::assertSame(ScenarioResult::FAILURE_OPERATIONAL, $operationalResult->failureType());
        self::assertFalse($operationalResult->isValidationFailure());
        self::assertTrue($operationalResult->isOperationalFailure());
    }

    public function testProcessExceptionPreservesAvailableExecutionMetadata(): void
    {
        $clockValues = [100.0, 100.125];
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (): array {
                throw new \RuntimeException('Composer process failed to start.');
            },
            static fn (): string => '2.8.12',
            static function () use (&$clockValues): float {
                $value = array_shift($clockValues);
                if (!is_float($value)) {
                    throw new \LogicException('No test clock value remains.');
                }

                return $value;
            }
        );
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        $result = $runner->run($project, $request, new Scenario('exact-target', $request->targets(), false));

        self::assertSame(ScenarioResult::FAILURE_OPERATIONAL, $result->failureType());
        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->outcome());
        self::assertSame(1, $result->exitCode());
        self::assertSame('2.8.12', $result->composerVersion());
        self::assertSame([
            'composer',
            'update',
            'fixture/dependency',
            '--no-scripts',
            '--no-plugins',
            '--no-install',
            '--no-audit',
            '--no-progress',
            '--no-interaction',
        ], $result->command());
        self::assertSame(125, $result->durationMs());
        self::assertStringContainsString('failed to start', $result->stderr());
        self::assertNull($result->candidateLockEvidence());
    }

    public function testVersionProbeFailureDoesNotDiscardASuccessfulScenario(): void
    {
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $directory): array {
                file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', json_encode([
                    'packages' => [['name' => 'fixture/dependency', 'version' => '2.0.0']],
                    'packages-dev' => [],
                ], JSON_THROW_ON_ERROR));

                return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
            },
            static function (): ?string {
                throw new \RuntimeException('Composer version probe failed.');
            }
        );
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        $result = $runner->run($project, $request, new Scenario('exact-target', $request->targets(), false));

        self::assertTrue($result->succeeded());
        self::assertNull($result->composerVersion());
        self::assertSame(0, $result->exitCode());
        self::assertNotNull($result->candidateLockEvidence());
    }

    public function testWorkspaceCreationFailureBecomesAnOperationalResult(): void
    {
        $workspaceManager = new FailingCreateWorkspaceManager();
        $runner = new ComposerScenarioRunner($workspaceManager, null, static function (): array {
            throw new \LogicException('The process must not run when workspace creation fails.');
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        $result = $runner->run($project, $request, new Scenario('test', $request->targets()));

        self::assertSame(ScenarioResult::FAILURE_OPERATIONAL, $result->failureType());
        self::assertSame(ScenarioResult::OUTCOME_WORKSPACE_FAILURE, $result->outcome());
        self::assertStringContainsString('Unable to create test workspace', $result->stderr());
        self::assertSame(0, $workspaceManager->removeCalls);
    }

    public function testNonDebugWorkspaceIsRemovedAfterAProcessFailure(): void
    {
        $snapshot = FixtureSnapshot::capture($this->fixtureProjectPath());
        $workspaceManager = new TrackingWorkspaceManager();
        $runner = new ComposerScenarioRunner($workspaceManager, null, static function (): array {
            throw new \RuntimeException('Synthetic process failure.');
        });

        $result = $this->runFixtureScenario($runner);

        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->outcome());
        self::assertNull($result->tempPath());
        self::assertCount(1, $workspaceManager->createdPaths);
        self::assertSame($workspaceManager->createdPaths, $workspaceManager->removedPaths);
        self::assertDirectoryDoesNotExist($workspaceManager->createdPaths[0]);
        $snapshot->assertUnchanged($this);
    }

    public function testDebugWorkspaceIsTheOnlyIntentionallyPreservedWorkspace(): void
    {
        $snapshot = FixtureSnapshot::capture($this->fixtureProjectPath());
        $workspaceManager = new TrackingWorkspaceManager();
        $runner = new ComposerScenarioRunner($workspaceManager, null, static function (array $command, string $directory): array {
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', json_encode([
                'packages' => [['name' => 'fixture/dependency', 'version' => '2.0.0']],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR));

            return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            [],
            \PhpUpgradePreflight\Core\Model\ReportFormat::JSON,
            null,
            true
        );

        try {
            $result = $runner->run($project, $request, new Scenario('exact-target', $request->targets()));

            self::assertTrue($result->succeeded());
            self::assertCount(1, $workspaceManager->createdPaths);
            self::assertSame([], $workspaceManager->removedPaths);
            self::assertSame($workspaceManager->createdPaths[0], $result->tempPath());
            self::assertSame($workspaceManager->createdPaths[0], $result->toArray()['temp_path']);
            self::assertDirectoryExists($result->tempPath());
            $snapshot->assertUnchanged($this);
        } finally {
            $workspaceManager->forceCleanup();
        }
    }

    public function testDebugWorkspaceIsPreservedAfterAProcessFailure(): void
    {
        $snapshot = FixtureSnapshot::capture($this->fixtureProjectPath());
        $workspaceManager = new TrackingWorkspaceManager();
        $runner = new ComposerScenarioRunner($workspaceManager, null, static function (): array {
            throw new \RuntimeException('Synthetic process failure.');
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            [],
            \PhpUpgradePreflight\Core\Model\ReportFormat::JSON,
            null,
            true
        );

        try {
            $result = $runner->run($project, $request, new Scenario('exact-target', $request->targets()));

            self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->outcome());
            self::assertCount(1, $workspaceManager->createdPaths);
            self::assertSame([], $workspaceManager->removedPaths);
            self::assertSame($workspaceManager->createdPaths[0], $result->tempPath());
            self::assertSame($workspaceManager->createdPaths[0], $result->toArray()['temp_path']);
            self::assertDirectoryExists($result->tempPath());
            $snapshot->assertUnchanged($this);
        } finally {
            $workspaceManager->forceCleanup();
        }
    }

    public function testInitializationCleanupFailureBecomesACleanupOutcomeWithTheLeakedPath(): void
    {
        $workspaceManager = new FailingInitializationCleanupWorkspaceManager();
        $processCalls = 0;
        $runner = new ComposerScenarioRunner($workspaceManager, null, static function () use (&$processCalls): array {
            ++$processCalls;

            throw new \LogicException('The process must not run after initialization cleanup fails.');
        });

        try {
            $result = $this->runFixtureScenario($runner);

            self::assertSame(0, $processCalls);
            self::assertSame(ScenarioResult::OUTCOME_CLEANUP_FAILURE, $result->outcome());
            self::assertTrue($result->isOperationalFailure());
            self::assertSame($workspaceManager->workspacePath, $result->tempPath());
            self::assertSame(PathExposurePolicy::ANALYZER_WORKSPACE, $result->toArray()['temp_path']);
            self::assertSame(0, $workspaceManager->removeCalls);
        } finally {
            $workspaceManager->forceCleanup();
        }
    }

    public function testWorkspaceCleanupFailureBecomesAnOperationalResult(): void
    {
        $snapshot = FixtureSnapshot::capture($this->fixtureProjectPath());
        $workspaceManager = new FailingCleanupWorkspaceManager();
        $runner = new ComposerScenarioRunner($workspaceManager, null, static function (array $command, string $directory): array {
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', json_encode([
                'packages' => [['name' => 'fixture/dependency', 'version' => '2.0.0']],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR));

            return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        try {
            $result = $runner->run($project, $request, new Scenario('test', $request->targets()));

            self::assertFalse($result->succeeded());
            self::assertSame(ScenarioResult::FAILURE_OPERATIONAL, $result->failureType());
            self::assertSame(ScenarioResult::OUTCOME_CLEANUP_FAILURE, $result->outcome());
            self::assertSame(0, $result->exitCode());
            self::assertStringContainsString('cleanup failed', $result->stderr());
            self::assertNotNull($result->tempPath());
            self::assertSame(PathExposurePolicy::ANALYZER_WORKSPACE, $result->toArray()['temp_path']);
            self::assertNotNull($result->candidateLockEvidence());
            $snapshot->assertUnchanged($this);
        } finally {
            $workspaceManager->forceCleanup();
        }
    }

    public function testWorkspaceCleanupFailurePreservesCollectedDiagnostics(): void
    {
        $workspaceManager = new FailingCleanupWorkspaceManager();
        $runner = new ComposerScenarioRunner($workspaceManager, null, static function (array $command): array {
            if ($command[1] === 'prohibits') {
                return [
                    'exit_code' => 0,
                    'stdout' => 'fixture/blocker 1.0.0 requires fixture/dependency (^1.0)',
                    'stderr' => '',
                ];
            }

            return [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
            ];
        });
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        try {
            $result = $runner->run($project, $request, new Scenario('exact-target', $request->targets()));

            self::assertSame(ScenarioResult::FAILURE_OPERATIONAL, $result->failureType());
            self::assertCount(1, $result->diagnostics());
            self::assertStringContainsString('fixture/blocker', $result->diagnostics()[0]->stdout());
            self::assertStringContainsString('cleanup failed', $result->stderr());
        } finally {
            $workspaceManager->forceCleanup();
        }
    }

    public function testCredentialBearingRepositoryFailureIsSanitizedBeforeResultStorage(): void
    {
        $fixtureContents = file_get_contents(
            dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR
            . 'composer-output-with-secrets.json'
        );
        self::assertIsString($fixtureContents);
        $fixture = json_decode($fixtureContents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['canaries'] ?? null);
        self::assertIsArray($fixture['repository_failure'] ?? null);

        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR . 'path-repository' . DIRECTORY_SEPARATOR . 'project';
        $repositoryPath = dirname($projectPath) . DIRECTORY_SEPARATOR . 'repository';
        $failure = $fixture['repository_failure'];
        self::assertIsInt($failure['exit_code'] ?? null);
        self::assertIsString($failure['stdout'] ?? null);
        self::assertIsString($failure['stderr'] ?? null);
        $exitCode = $failure['exit_code'];
        $stdout = $failure['stdout'];
        $stderr = $failure['stderr'];
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $workspace) use ($exitCode, $stdout, $stderr, $projectPath, $repositoryPath): array {
                return [
                    'exit_code' => $exitCode,
                    'stdout' => $stdout . "\nProject: " . $projectPath . "\nWorkspace: " . $workspace,
                    'stderr' => $stderr . "\nRepository: " . $repositoryPath . DIRECTORY_SEPARATOR . 'private',
                ];
            },
            static fn (): string => '2.8.12'
        );
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        $result = $runner->run($project, $request, new Scenario('credential-repository', $request->targets()));
        $stored = $result->stdout() . "\n" . $result->stderr() . "\n"
            . json_encode($result->toArray(), JSON_THROW_ON_ERROR);

        self::assertSame(ScenarioResult::OUTCOME_PROCESS_FAILURE, $result->outcome());
        self::assertNull($result->tempPath());
        self::assertStringContainsString(PathExposurePolicy::PROJECT_ROOT, $stored);
        self::assertStringContainsString(PathExposurePolicy::ANALYZER_WORKSPACE, $stored);
        self::assertStringContainsString(PathExposurePolicy::LOCAL_REPOSITORY, $stored);
        self::assertStringContainsString('[REDACTED_URL]', $stored);
        foreach ($fixture['canaries'] as $label => $canary) {
            if (str_contains($stored, $canary)) {
                self::fail(sprintf('Sensitive canary %s reached the stored repository failure.', $label));
            }
        }
    }

    public function testRestrictedStatePreparationFailureIsAWorkspaceFailureNotAComposerFailure(): void
    {
        $workspaces = new BlockedRestrictedStateWorkspaceManager();
        $processCalls = 0;
        $runner = new ComposerScenarioRunner(
            $workspaces,
            null,
            static function () use (&$processCalls): array {
                ++$processCalls;

                throw new \LogicException('No Composer process exists once workspace preparation fails.');
            },
            static fn (): string => '2.8.12'
        );
        $projectPath = $this->fixtureProjectPath();
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
            ComposerExecutionConfiguration::restricted()
        );

        try {
            $result = $runner->run($project, $request, new Scenario('restricted-state-failure', $request->targets()));

            self::assertSame(0, $processCalls);
            self::assertSame(ScenarioResult::OUTCOME_WORKSPACE_FAILURE, $result->outcome());
            self::assertTrue($result->isOperationalFailure());
            self::assertStringContainsString(
                'Unable to create analyzer-owned restricted Composer state.',
                $result->stderr()
            );
        } finally {
            $workspaces->forceCleanup();
        }
    }

    public function testDiagnosticOutcomesSeparateTimeoutsFromOrdinaryNonZeroExits(): void
    {
        $timedOut = new Process(['composer', 'prohibits']);
        $timedOut->setTimeout(60);
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command) use ($timedOut): array {
                if ($command[1] === 'prohibits') {
                    throw new ProcessTimedOutException($timedOut, ProcessTimedOutException::TYPE_GENERAL);
                }

                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
                ];
            }
        );

        $result = $this->runFixtureScenario($runner);

        self::assertCount(1, $result->diagnostics());
        $diagnostic = $result->diagnostics()[0];
        self::assertSame(ScenarioResult::OUTCOME_TIMEOUT, $diagnostic->outcome());
        self::assertSame(1, $diagnostic->exitCode());
        self::assertSame(ScenarioResult::OUTCOME_TIMEOUT, $diagnostic->toArray()['outcome']);
    }

    public function testCompletedDiagnosticsRemainUsableEvidenceDespiteANonZeroExit(): void
    {
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command): array {
                if ($command[1] === 'prohibits') {
                    return [
                        'exit_code' => 1,
                        'stdout' => 'fixture/blocker 1.0.0 requires fixture/dependency (^1.0)',
                        'stderr' => '',
                    ];
                }

                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
                ];
            }
        );

        $result = $this->runFixtureScenario($runner);

        self::assertCount(1, $result->diagnostics());
        $diagnostic = $result->diagnostics()[0];
        self::assertSame(ScenarioResult::OUTCOME_SUCCESS, $diagnostic->outcome());
        self::assertSame(1, $diagnostic->exitCode());
    }

    public function testMissingComposerDuringADiagnosticIsDistinguishableFromASolverExit(): void
    {
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command): array {
                if ($command[1] === 'prohibits') {
                    return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'composer: command not found'];
                }

                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
                ];
            }
        );

        $result = $this->runFixtureScenario($runner);

        self::assertCount(1, $result->diagnostics());
        self::assertSame(ScenarioResult::OUTCOME_COMPOSER_MISSING, $result->diagnostics()[0]->outcome());
    }

    public function testProbeCleanupFailureKeepsTheProbeAnswerAndRecordsAnUncertainty(): void
    {
        $workspaces = new FailingProbeCleanupWorkspaceManager();
        $runner = new ComposerScenarioRunner(
            $workspaces,
            null,
            static fn (): array => [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Synthetic operational stop.',
            ],
            null,
            null,
            static fn (): array => ['exit_code' => 0, 'stdout' => 'Composer version 2.8.12', 'stderr' => '']
        );

        try {
            $result = $this->runFixtureScenario($runner);

            self::assertSame('2.8.12', $result->composerVersion(), 'A cleanup failure must not discard the probe answer.');
            self::assertIsString($workspaces->refusedPath);
            self::assertCount(1, $runner->probeCleanupUncertainties());
            $uncertainty = $runner->probeCleanupUncertainties()[0];
            self::assertStringContainsString('Composer metadata probe workspace cleanup failed', $uncertainty);
            self::assertStringContainsString(PathExposurePolicy::ANALYZER_WORKSPACE, $uncertainty);
            self::assertStringNotContainsString($workspaces->refusedPath, $uncertainty);
        } finally {
            $workspaces->forceCleanup();
        }
    }

    public function testProbeWorkspacesAreRemovedThroughTheInjectedWorkspaceManager(): void
    {
        $workspaces = new TrackingWorkspaceManager();
        $runner = new ComposerScenarioRunner(
            $workspaces,
            null,
            static fn (): array => [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Synthetic operational stop.',
            ],
            null,
            null,
            static fn (): array => ['exit_code' => 0, 'stdout' => 'Composer version 2.8.12', 'stderr' => '']
        );

        try {
            $result = $this->runFixtureScenario($runner);

            self::assertSame('2.8.12', $result->composerVersion());
            self::assertSame([], $runner->probeCleanupUncertainties());
            self::assertCount(1, $workspaces->createdPaths);
            self::assertCount(2, $workspaces->removedPaths, 'The probe and the scenario workspace are both removed.');
            foreach ($workspaces->removedPaths as $removedPath) {
                self::assertDirectoryDoesNotExist($removedPath);
            }
        } finally {
            $workspaces->forceCleanup();
        }
    }

    /**
     * A candidate lock is discarded with its workspace, so an entry the analyzer could not index has
     * to be collected on the runner or it never reaches the report: the published candidate package
     * count excludes the entry while the recorded hash still covers the whole file.
     */
    public function testCandidateLockEntriesThatCannotBeIndexedAreRecordedAsUncertainties(): void
    {
        $lockContents = json_encode([
            'content-hash' => 'fixture-content-hash',
            'packages' => [
                ['name' => 'fixture/dependency', 'version' => '2.0.0'],
                ['name' => 'not a package', 'version' => '1.0.0'],
                ['name' => 'fixture/versionless'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $directory) use ($lockContents): array {
                file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', $lockContents);

                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
            static fn (): string => '2.8.12'
        );

        $result = $this->runFixtureScenario($runner);

        self::assertNotNull($result->candidateLockEvidence());
        self::assertSame(1, $result->candidateLockEvidence()->packageCount());
        $uncertainties = $runner->candidateLockUncertainties();
        self::assertCount(2, $uncertainties);
        self::assertStringContainsString('not a package', $uncertainties[0]);
        self::assertStringContainsString('fixture/versionless', $uncertainties[1]);
        foreach ($uncertainties as $uncertainty) {
            self::assertStringContainsString('Composer candidate lock entries were skipped', $uncertainty);
        }

        $this->runFixtureScenario($runner);
        self::assertSame(
            $uncertainties,
            $runner->candidateLockUncertainties(),
            'Scenarios resolving to the same candidate lock must not repeat the same omission.'
        );

        $runner->resetAnalysisCaches();
        self::assertSame([], $runner->candidateLockUncertainties());
    }

    public function testAFullyReadableCandidateLockRecordsNoUncertainty(): void
    {
        $lockContents = json_encode([
            'packages' => [['name' => 'fixture/dependency', 'version' => '2.0.0']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $directory) use ($lockContents): array {
                file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.lock', $lockContents);

                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
            static fn (): string => '2.8.12'
        );

        self::assertNotNull($this->runFixtureScenario($runner)->lock());
        self::assertSame([], $runner->candidateLockUncertainties());
    }

    private function createProjectWithPathRepository(string $url, string $type = 'path'): string
    {
        $projectPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-test-' . bin2hex(random_bytes(8));
        mkdir($projectPath, 0700, true);
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
            'name' => 'fixture/environment-path-project',
            'repositories' => [['type' => $type, 'url' => $url]],
            'require' => ['php' => '^8.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $projectPath;
    }

    private function runFixtureScenario(ComposerScenarioRunner $runner): ScenarioResult
    {
        $projectPath = $this->fixtureProjectPath();
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        return $runner->run($project, $request, new Scenario('exact-target', $request->targets()));
    }

    private function fixtureProjectPath(): string
    {
        return dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
    }
}

/**
 * Creates a real workspace whose analyzer-owned restricted Composer state cannot
 * be built, because the state root already exists as a file.
 */
final class BlockedRestrictedStateWorkspaceManager implements WorkspaceManager
{
    private TemporaryWorkspaceManager $delegate;
    /** @var list<string> */
    private array $createdPaths = [];

    public function __construct()
    {
        $this->delegate = new TemporaryWorkspaceManager();
    }

    public function createFromProject(string $projectPath): string
    {
        $path = $this->delegate->createFromProject($projectPath);
        $this->createdPaths[] = $path;
        file_put_contents($path . DIRECTORY_SEPARATOR . '.php-upgrade-preflight-composer', 'not a directory');

        return $path;
    }

    public function remove(string $path): void
    {
        $this->delegate->remove($path);
    }

    public function forceCleanup(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_dir($path)) {
                $this->delegate->remove($path);
            }
        }
    }
}

/** Refuses to remove the metadata probe workspace, but cleans scenario workspaces. */
final class FailingProbeCleanupWorkspaceManager implements WorkspaceManager
{
    public ?string $refusedPath = null;

    private TemporaryWorkspaceManager $delegate;
    /** @var list<string> */
    private array $leakedPaths = [];

    public function __construct()
    {
        $this->delegate = new TemporaryWorkspaceManager();
    }

    public function createFromProject(string $projectPath): string
    {
        return $this->delegate->createFromProject($projectPath);
    }

    public function remove(string $path): void
    {
        if (!str_contains(basename($path), 'composer-probe-')) {
            $this->delegate->remove($path);

            return;
        }

        $this->refusedPath = $path;
        $this->leakedPaths[] = $path;

        throw new WorkspaceCleanupException(
            $path,
            sprintf('Synthetic probe cleanup failure in %s.', $path)
        );
    }

    public function forceCleanup(): void
    {
        foreach ($this->leakedPaths as $path) {
            if (is_dir($path)) {
                $this->delegate->remove($path);
            }
        }
    }
}

final class FailingCreateWorkspaceManager implements WorkspaceManager
{
    public int $createCalls = 0;
    public int $removeCalls = 0;

    public function createFromProject(string $projectPath): string
    {
        ++$this->createCalls;

        throw new \RuntimeException('Unable to create test workspace.');
    }

    public function remove(string $path): void
    {
        ++$this->removeCalls;
    }
}

final class TrackingWorkspaceManager implements WorkspaceManager
{
    private TemporaryWorkspaceManager $delegate;
    /** @var list<string> */
    public array $createdPaths = [];
    /** @var list<string> */
    public array $removedPaths = [];

    public function __construct()
    {
        $this->delegate = new TemporaryWorkspaceManager();
    }

    public function createFromProject(string $projectPath): string
    {
        $path = $this->delegate->createFromProject($projectPath);
        $this->createdPaths[] = $path;

        return $path;
    }

    public function remove(string $path): void
    {
        $this->delegate->remove($path);
        $this->removedPaths[] = $path;
    }

    public function forceCleanup(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_dir($path)) {
                $this->delegate->remove($path);
            }
        }
    }
}

final class ClockAdvancingWorkspaceManager implements WorkspaceManager
{
    private TemporaryWorkspaceManager $delegate;
    private \Closure $onRemove;
    /** @var list<string> */
    public array $createdPaths = [];
    /** @var list<string> */
    public array $removedPaths = [];

    public function __construct(callable $onRemove)
    {
        $this->delegate = new TemporaryWorkspaceManager();
        $this->onRemove = \Closure::fromCallable($onRemove);
    }

    public function createFromProject(string $projectPath): string
    {
        $path = $this->delegate->createFromProject($projectPath);
        $this->createdPaths[] = $path;

        return $path;
    }

    public function remove(string $path): void
    {
        $this->delegate->remove($path);
        $this->removedPaths[] = $path;
        ($this->onRemove)();
    }

    public function forceCleanup(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_dir($path)) {
                $this->delegate->remove($path);
            }
        }
    }
}

final class FailingCleanupWorkspaceManager implements WorkspaceManager
{
    private TemporaryWorkspaceManager $delegate;
    private ?string $workspacePath = null;

    public function __construct()
    {
        $this->delegate = new TemporaryWorkspaceManager();
    }

    public function createFromProject(string $projectPath): string
    {
        $this->workspacePath = $this->delegate->createFromProject($projectPath);

        return $this->workspacePath;
    }

    public function remove(string $path): void
    {
        throw new \RuntimeException('Synthetic cleanup failure.');
    }

    public function forceCleanup(): void
    {
        if ($this->workspacePath !== null) {
            $this->delegate->remove($this->workspacePath);
            $this->workspacePath = null;
        }
    }
}

final class FailingInitializationCleanupWorkspaceManager implements WorkspaceManager
{
    public int $removeCalls = 0;
    public ?string $workspacePath = null;

    public function createFromProject(string $projectPath): string
    {
        $this->workspacePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-leaked-' . bin2hex(random_bytes(8));
        mkdir($this->workspacePath, 0700, true);

        throw new WorkspaceCleanupException($this->workspacePath, 'Synthetic initialization cleanup failure.');
    }

    public function remove(string $path): void
    {
        ++$this->removeCalls;
    }

    public function forceCleanup(): void
    {
        if ($this->workspacePath !== null) {
            (new Filesystem())->remove($this->workspacePath);
            $this->workspacePath = null;
        }
    }
}
