<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Filesystem\TemporaryWorkspaceManager;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceManager;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ComposerScenarioRunnerTest extends TestCase
{
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
    }

    public function testItUpdatesAnExistingDevRequirementWithoutDuplicatingItInRequire(): void
    {
        $captured = null;
        $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory) use (&$captured): array {
            $captured = json_decode((string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'), true, 512, JSON_THROW_ON_ERROR);

            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic operational stop.'];
        });
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('phpunit/phpunit', '^10.0')]);

        $runner->run((new ProjectStateBuilder())->build($projectPath), $request, new Scenario('test', $request->targets()));

        self::assertIsArray($captured);
        self::assertSame('^10.0', $captured['require-dev']['phpunit/phpunit']);
        self::assertArrayNotHasKey('phpunit/phpunit', $captured['require']);
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
            Path::canonicalize(dirname($projectPath) . DIRECTORY_SEPARATOR . 'repository' . DIRECTORY_SEPARATOR . 'fixture-dependency'),
            Path::canonicalize($capturedUrl)
        );
    }

    public function testItPreservesEnvironmentVariablesInPathRepositories(): void
    {
        foreach (['$HOME/git/pkg', '${HOME}/git/pkg', '%USERPROFILE%/git/pkg'] as $url) {
            $projectPath = $this->createProjectWithPathRepository($url);
            $capturedUrl = null;
            $runner = new ComposerScenarioRunner(null, null, static function (array $command, string $directory) use (&$capturedUrl): array {
                $composer = json_decode((string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'composer.json'), true, 512, JSON_THROW_ON_ERROR);
                $capturedUrl = $composer['repositories'][0]['url'];

                return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic operational stop.'];
            });
            $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^1.0')]);

            try {
                $runner->run((new ProjectStateBuilder())->build($projectPath), $request, new Scenario('test', $request->targets()));

                self::assertSame($url, $capturedUrl);
            } finally {
                (new Filesystem())->remove($projectPath);
            }
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

        self::assertSame(ScenarioResult::FAILURE_SOLVER, $solverRunner->run($project, $request, $scenario)->failureType());
        self::assertSame(ScenarioResult::FAILURE_OPERATIONAL, $operationalRunner->run($project, $request, $scenario)->failureType());
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
        self::assertStringContainsString('Unable to create test workspace', $result->stderr());
        self::assertSame(0, $workspaceManager->removeCalls);
    }

    public function testWorkspaceCleanupFailureBecomesAnOperationalResult(): void
    {
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
            self::assertStringContainsString('cleanup failed', $result->stderr());
            self::assertNotNull($result->tempPath());
        } finally {
            $workspaceManager->forceCleanup();
        }
    }

    private function createProjectWithPathRepository(string $url): string
    {
        $projectPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-test-' . bin2hex(random_bytes(8));
        mkdir($projectPath, 0700, true);
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
            'name' => 'fixture/environment-path-project',
            'repositories' => [['type' => 'path', 'url' => $url]],
            'require' => ['php' => '^8.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $projectPath;
    }
}

final class FailingCreateWorkspaceManager implements WorkspaceManager
{
    public int $removeCalls = 0;

    public function createFromProject(string $projectPath): string
    {
        throw new \RuntimeException('Unable to create test workspace.');
    }

    public function remove(string $path): void
    {
        ++$this->removeCalls;
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
