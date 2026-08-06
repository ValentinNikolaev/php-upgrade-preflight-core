<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Filesystem\TemporaryWorkspaceManager;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceManager;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

final class ComposerScenarioRunner
{
    private WorkspaceManager $workspaces;
    private JsonFileReader $reader;
    /** @var \Closure(list<string>, string): array{exit_code: int, stdout: string, stderr: string} */
    private \Closure $processRunner;

    /**
     * @param null|callable(list<string>, string): array{exit_code: int, stdout: string, stderr: string} $processRunner
     */
    public function __construct(
        ?WorkspaceManager $workspaces = null,
        ?JsonFileReader $reader = null,
        ?callable $processRunner = null
    ) {
        $this->workspaces = $workspaces ?? new TemporaryWorkspaceManager();
        $this->reader = $reader ?? new JsonFileReader();
        $this->processRunner = $processRunner === null
            ? \Closure::fromCallable([$this, 'runProcess'])
            : \Closure::fromCallable($processRunner);
    }

    public function run(ProjectState $project, UpgradeRequest $request, Scenario $scenario): ScenarioResult
    {
        $tempPath = null;

        try {
            $tempPath = $this->workspaces->createFromProject($project->path());
            if (!$scenario->isBaselineValidation()) {
                $this->applyTemporaryComposerChanges($tempPath, $project->path(), $scenario);
            }
            $command = $this->buildCommand($scenario);
            $process = ($this->processRunner)($command, $tempPath);

            $lock = null;
            $lockPath = $tempPath . DIRECTORY_SEPARATOR . 'composer.lock';
            if ($process['exit_code'] === 0 && is_file($lockPath)) {
                $lock = new ComposerLock($this->reader->read($lockPath));
            }

            $failureType = null;
            if ($process['exit_code'] !== 0) {
                if ($scenario->isBaselineValidation()) {
                    $failureType = ScenarioResult::FAILURE_VALIDATION;
                } else {
                    $failureType = $this->isSolverFailure($process['stdout'], $process['stderr'])
                        ? ScenarioResult::FAILURE_SOLVER
                        : ScenarioResult::FAILURE_OPERATIONAL;
                }
            } elseif ($lock === null) {
                $failureType = ScenarioResult::FAILURE_OPERATIONAL;
            }

            $result = new ScenarioResult(
                $scenario,
                $process['exit_code'],
                $process['stdout'],
                $process['stderr'],
                $lock,
                $request->debug() ? $tempPath : null,
                $failureType
            );
        } catch (\Throwable $exception) {
            $result = new ScenarioResult(
                $scenario,
                1,
                '',
                $exception->getMessage(),
                null,
                $request->debug() ? $tempPath : null,
                ScenarioResult::FAILURE_OPERATIONAL
            );
        }

        if (!$request->debug() && $tempPath !== null) {
            try {
                $this->workspaces->remove($tempPath);
            } catch (\Throwable $exception) {
                return new ScenarioResult(
                    $scenario,
                    1,
                    $result->stdout(),
                    trim($result->stderr() . PHP_EOL . sprintf('Temporary workspace cleanup failed: %s', $exception->getMessage())),
                    null,
                    $tempPath,
                    ScenarioResult::FAILURE_OPERATIONAL
                );
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function buildCommand(Scenario $scenario): array
    {
        if ($scenario->isBaselineValidation()) {
            return [
                'composer',
                'validate',
                '--check-lock',
                '--no-check-publish',
                '--no-scripts',
                '--no-plugins',
                '--no-interaction',
            ];
        }

        $command = ['composer', 'update'];

        foreach ($scenario->targets()->packageTargets() as $target) {
            $command[] = $target->package();
        }

        if ($scenario->withAllDependencies()) {
            $command[] = '--with-all-dependencies';
        }

        if ($scenario->minimalChanges()) {
            $command[] = '--minimal-changes';
        }

        $command[] = '--no-scripts';
        $command[] = '--no-plugins';
        $command[] = '--no-install';
        $command[] = '--no-interaction';

        return $command;
    }

    /**
     * @param list<string> $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, string $workingDirectory): array
    {
        $process = new Process($command, $workingDirectory, ['COMPOSER_NO_INTERACTION' => '1'], null, 300);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function applyTemporaryComposerChanges(string $tempPath, string $projectPath, Scenario $scenario): void
    {
        $composerPath = $tempPath . DIRECTORY_SEPARATOR . 'composer.json';
        $data = $this->reader->read($composerPath);

        $data = $this->absolutePathRepositories($data, $projectPath);

        foreach ($scenario->targets()->packageTargets() as $target) {
            if (isset($data['require-dev']) && is_array($data['require-dev']) && array_key_exists($target->package(), $data['require-dev'])
                && (!isset($data['require']) || !is_array($data['require']) || !array_key_exists($target->package(), $data['require']))) {
                $data['require-dev'][$target->package()] = $target->constraint();
                continue;
            }

            if (!isset($data['require']) || !is_array($data['require'])) {
                $data['require'] = [];
            }

            $data['require'][$target->package()] = $target->constraint();
        }

        if ($scenario->targets()->targetPhp() !== null) {
            $data['config']['platform']['php'] = $scenario->targets()->targetPhp();
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($composerPath, $encoded) === false) {
            throw new \RuntimeException(sprintf('Unable to write temporary Composer manifest "%s".', $composerPath));
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function absolutePathRepositories(array $data, string $projectPath): array
    {
        if (!isset($data['repositories']) || !is_array($data['repositories'])) {
            return $data;
        }

        foreach ($data['repositories'] as $key => $repository) {
            if (!is_array($repository) || ($repository['type'] ?? null) !== 'path' || !isset($repository['url']) || !is_string($repository['url'])) {
                continue;
            }

            $url = $repository['url'];
            if ($url === '' || Path::isAbsolute($url) || str_starts_with($url, '~') || $this->containsEnvironmentVariable($url)) {
                continue;
            }

            $repository['url'] = Path::makeAbsolute($url, $projectPath);
            $data['repositories'][$key] = $repository;
        }

        return $data;
    }

    private function containsEnvironmentVariable(string $path): bool
    {
        return preg_match('/\$(?:\{[A-Za-z_][A-Za-z0-9_]*\}|[A-Za-z_][A-Za-z0-9_]*)|%[A-Za-z_][A-Za-z0-9_]*%/', $path) === 1;
    }

    private function isSolverFailure(string $stdout, string $stderr): bool
    {
        $output = $stdout . "\n" . $stderr;

        return stripos($output, 'Your requirements could not be resolved to an installable set of packages') !== false
            || preg_match('/(?:^|\n)\s*- Root composer\.json requires /i', $output) === 1;
    }
}
