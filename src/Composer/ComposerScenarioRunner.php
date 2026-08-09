<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use Composer\Semver\Semver;
use PhpUpgradePreflight\Core\Filesystem\TemporaryWorkspaceManager;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceCleanupException;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceManager;
use PhpUpgradePreflight\Core\Model\CandidateLockEvidence;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ComposerScenarioRunner
{
    private const ABSENT_EXTENSION_MIN_COMPOSER_VERSION = '2.2.0';
    private const LOCKED_DIAGNOSTIC_MIN_COMPOSER_VERSION = '2.4.0';
    /** @var list<string> */
    private const COMPOSER_SAFETY_OPTIONS = ['--no-scripts', '--no-plugins'];

    private WorkspaceManager $workspaces;
    private JsonFileReader $reader;
    /** @var \Closure(list<string>, string): array{exit_code: int, stdout: string, stderr: string} */
    private \Closure $processRunner;
    /** @var \Closure(): ?string */
    private \Closure $composerVersionResolver;
    /** @var \Closure(list<string>): array{exit_code: int, stdout: string, stderr: string} */
    private \Closure $composerVersionProcessRunner;
    /** @var \Closure(): float */
    private \Closure $clock;
    private bool $composerVersionResolved = false;
    private ?string $composerVersion = null;
    /** @var array<string, ComposerDiagnostic> */
    private array $diagnosticCache = [];

    /**
     * @param null|callable(list<string>, string): array{exit_code: int, stdout: string, stderr: string} $processRunner
     * @param null|callable(): ?string $composerVersionResolver
     * @param null|callable(): float $clock
     * @param null|callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $composerVersionProcessRunner
     */
    public function __construct(
        ?WorkspaceManager $workspaces = null,
        ?JsonFileReader $reader = null,
        ?callable $processRunner = null,
        ?callable $composerVersionResolver = null,
        ?callable $clock = null,
        ?callable $composerVersionProcessRunner = null
    ) {
        $this->workspaces = $workspaces ?? new TemporaryWorkspaceManager();
        $this->reader = $reader ?? new JsonFileReader();
        $this->processRunner = $processRunner === null
            ? \Closure::fromCallable([$this, 'runProcess'])
            : \Closure::fromCallable($processRunner);
        $this->composerVersionProcessRunner = $composerVersionProcessRunner === null
            ? \Closure::fromCallable([$this, 'runVersionProcess'])
            : \Closure::fromCallable($composerVersionProcessRunner);
        $this->composerVersionResolver = $composerVersionResolver === null
            ? ($processRunner === null || $composerVersionProcessRunner !== null
                ? \Closure::fromCallable([$this, 'detectComposerVersion'])
                : static fn (): ?string => null)
            : \Closure::fromCallable($composerVersionResolver);
        $this->clock = $clock === null
            ? static fn (): float => microtime(true)
            : \Closure::fromCallable($clock);
    }

    public function run(
        ProjectState $project,
        UpgradeRequest $request,
        Scenario $scenario,
        ?TargetPlatform $platform = null
    ): ScenarioResult {
        $platform = $platform ?? TargetPlatform::fromRequest($request, $project);
        $tempPath = null;
        $command = $this->buildCommand($scenario);
        $composerVersion = $this->resolveComposerVersion();
        if (!$scenario->isBaselineValidation()
            && $platform->hasAbsentExtensionAssumptions()
            && !$this->supportsAbsentExtensionOverrides($composerVersion)) {
            return new ScenarioResult(
                $scenario,
                1,
                '',
                sprintf(
                    'Composer %s cannot simulate absent extensions; Composer %s or newer is required.',
                    $composerVersion,
                    self::ABSENT_EXTENSION_MIN_COMPOSER_VERSION
                ),
                null,
                null,
                ScenarioResult::FAILURE_OPERATIONAL,
                $composerVersion,
                $command,
                0,
                null,
                [],
                ScenarioResult::OUTCOME_PROCESS_FAILURE
            );
        }
        $durationMs = 0;
        $startedAt = null;
        $phase = 'workspace';
        $cleanupFailedDuringCreation = false;

        try {
            $tempPath = $this->workspaces->createFromProject($project->path());
            $phase = 'preparation';
            if (!$scenario->isBaselineValidation()) {
                $this->applyTemporaryComposerChanges($tempPath, $project->path(), $scenario, $platform);
            }
            $phase = 'process';
            $startedAt = ($this->clock)();
            $process = ($this->processRunner)($command, $tempPath);
            $durationMs = $this->elapsedMilliseconds($startedAt);

            $lock = null;
            $candidateLockEvidence = null;
            $lockPath = $tempPath . DIRECTORY_SEPARATOR . 'composer.lock';
            $phase = 'lockfile';
            if ($process['exit_code'] === 0 && is_file($lockPath)) {
                $manifest = new ComposerJson($this->reader->read($tempPath . DIRECTORY_SEPARATOR . 'composer.json'));
                $lock = new ComposerLock($this->reader->read($lockPath), array_keys($manifest->rootRequirements()));
                $candidateLockEvidence = CandidateLockEvidence::fromFile($lockPath, $lock);
            }

            $failureType = null;
            $outcome = ScenarioResult::OUTCOME_SUCCESS;
            $diagnostics = [];
            if ($process['exit_code'] !== 0) {
                if ($this->indicatesMissingComposer($process['exit_code'], $process['stdout'], $process['stderr'])) {
                    $failureType = ScenarioResult::FAILURE_OPERATIONAL;
                    $outcome = ScenarioResult::OUTCOME_COMPOSER_MISSING;
                } elseif ($scenario->isBaselineValidation()) {
                    $failureType = ScenarioResult::FAILURE_VALIDATION;
                    $outcome = ScenarioResult::OUTCOME_VALIDATION_FAILURE;
                } else {
                    $failureType = $this->isSolverFailure($process['stdout'], $process['stderr'])
                        ? ScenarioResult::FAILURE_SOLVER
                        : ScenarioResult::FAILURE_OPERATIONAL;
                    $outcome = $failureType === ScenarioResult::FAILURE_SOLVER
                        ? ScenarioResult::OUTCOME_SOLVER_FAILURE
                        : ScenarioResult::OUTCOME_PROCESS_FAILURE;
                }
            } elseif ($lock === null) {
                $failureType = ScenarioResult::FAILURE_OPERATIONAL;
                $outcome = ScenarioResult::OUTCOME_LOCKFILE_MISSING;
            }

            if ($failureType === ScenarioResult::FAILURE_SOLVER) {
                $diagnostics = $this->runTargetDiagnostics($project, $request, $scenario, $tempPath, $platform);
            }

            $result = new ScenarioResult(
                $scenario,
                $process['exit_code'],
                $process['stdout'],
                $process['stderr'],
                $lock,
                $request->debug() ? $tempPath : null,
                $failureType,
                $composerVersion,
                $command,
                $durationMs,
                $candidateLockEvidence,
                $diagnostics,
                $outcome
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof WorkspaceCleanupException) {
                $tempPath = $exception->workspacePath();
                $cleanupFailedDuringCreation = true;
            }

            if ($startedAt !== null && $durationMs === 0) {
                $durationMs = $this->elapsedMilliseconds($startedAt);
            }

            $stdout = '';
            $stderr = $exception->getMessage();
            $exitCode = 1;
            if ($exception instanceof ProcessTimedOutException) {
                $timedOutProcess = $exception->getProcess();
                try {
                    $stdout = $timedOutProcess->getOutput();
                    $stderr = $timedOutProcess->getErrorOutput();
                } catch (\Throwable) {
                    $stdout = '';
                    $stderr = '';
                }
                $stderr = trim($stderr . PHP_EOL . $exception->getMessage());
                $exitCode = $timedOutProcess->getExitCode() ?? 1;
            }

            $result = new ScenarioResult(
                $scenario,
                $exitCode,
                $stdout,
                $stderr,
                null,
                $request->debug() || $cleanupFailedDuringCreation ? $tempPath : null,
                ScenarioResult::FAILURE_OPERATIONAL,
                $composerVersion,
                $command,
                $durationMs,
                null,
                [],
                $this->exceptionOutcome($exception, $phase)
            );
        }

        if (!$request->debug() && $tempPath !== null && !$cleanupFailedDuringCreation) {
            try {
                $this->workspaces->remove($tempPath);
            } catch (\Throwable $exception) {
                return new ScenarioResult(
                    $scenario,
                    $result->exitCode(),
                    $result->stdout(),
                    trim($result->stderr() . PHP_EOL . sprintf('Temporary workspace cleanup failed: %s', $exception->getMessage())),
                    null,
                    $tempPath,
                    ScenarioResult::FAILURE_OPERATIONAL,
                    $result->composerVersion(),
                    $result->command(),
                    $result->durationMs(),
                    $result->candidateLockEvidence(),
                    $result->diagnostics(),
                    ScenarioResult::OUTCOME_CLEANUP_FAILURE
                );
            }
        }

        return $result;
    }

    public function resetDiagnosticCache(): void
    {
        $this->diagnosticCache = [];
    }

    /** @return list<string> */
    private function buildCommand(Scenario $scenario): array
    {
        if ($scenario->isBaselineValidation()) {
            return array_merge([
                'composer',
                'validate',
                '--check-lock',
                '--no-check-publish',
            ], self::COMPOSER_SAFETY_OPTIONS, [
                '--no-interaction',
            ]);
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

        $command = array_merge($command, self::COMPOSER_SAFETY_OPTIONS);
        $command[] = '--no-install';
        $command[] = '--no-audit';
        $command[] = '--no-progress';
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

    private function detectComposerVersion(): ?string
    {
        $process = ($this->composerVersionProcessRunner)(array_merge(
            ['composer', '--version', '--no-ansi'],
            self::COMPOSER_SAFETY_OPTIONS,
            ['--no-interaction']
        ));

        if ($process['exit_code'] !== 0) {
            return null;
        }

        $output = trim($process['stdout'] . "\n" . $process['stderr']);
        if (preg_match('/\bComposer(?:\s+version)?\s+([^\s]+)/i', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param list<string> $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runVersionProcess(array $command): array
    {
        $process = new Process($command, null, ['COMPOSER_NO_INTERACTION' => '1'], null, 30);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function resolveComposerVersion(): ?string
    {
        if ($this->composerVersionResolved) {
            return $this->composerVersion;
        }

        $this->composerVersionResolved = true;

        try {
            $version = ($this->composerVersionResolver)();
            $this->composerVersion = $version === null || trim($version) === '' ? null : trim($version);
        } catch (\Throwable $exception) {
            $this->composerVersion = null;
        }

        return $this->composerVersion;
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return max(0, (int) round(((float) ($this->clock)() - $startedAt) * 1000));
    }

    private function applyTemporaryComposerChanges(
        string $tempPath,
        string $projectPath,
        Scenario $scenario,
        TargetPlatform $platform
    ): void {
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

        foreach ($platform->composerPlatformOverrides() as $extension => $value) {
            $data['config']['platform'][$extension] = $value;
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

    private function exceptionOutcome(\Throwable $exception, string $phase): string
    {
        if ($exception instanceof WorkspaceCleanupException) {
            return ScenarioResult::OUTCOME_CLEANUP_FAILURE;
        }

        if ($exception instanceof ProcessTimedOutException) {
            return ScenarioResult::OUTCOME_TIMEOUT;
        }

        if ($exception instanceof InvalidJsonException) {
            return ScenarioResult::OUTCOME_INVALID_JSON;
        }

        if ($phase === 'process' && $this->indicatesMissingComposer(1, '', $exception->getMessage())) {
            return ScenarioResult::OUTCOME_COMPOSER_MISSING;
        }

        if ($phase === 'process') {
            return ScenarioResult::OUTCOME_PROCESS_FAILURE;
        }

        return ScenarioResult::OUTCOME_WORKSPACE_FAILURE;
    }

    private function indicatesMissingComposer(int $exitCode, string $stdout, string $stderr): bool
    {
        if (in_array($exitCode, [127, 9009], true)) {
            return true;
        }

        $output = $stdout . "\n" . $stderr;

        return preg_match('/(?:composer(?:\.bat|\.phar)?(?: executable)? (?:was |is )?(?:unavailable|missing|not found)|composer:\s*(?:command\s+)?not found|[\'\"]composer[\'\"] is not recognized|could not open input file:\s*composer|createprocess failed[^\n]*error=2|the system cannot find the file specified)/i', $output) === 1;
    }

    /** @return list<ComposerDiagnostic> */
    private function runTargetDiagnostics(
        ProjectState $project,
        UpgradeRequest $request,
        Scenario $scenario,
        string $workingDirectory,
        TargetPlatform $platform
    ): array {
        $diagnostics = [];

        foreach ($scenario->targets()->all() as $target) {
            if (!$this->targetNeedsDiagnostic($project, $request, $target->package(), $target->constraint())) {
                continue;
            }

            $cacheKey = $this->diagnosticCacheKey(
                $project,
                $scenario,
                $target->package(),
                $target->constraint(),
                $platform
            );
            if (isset($this->diagnosticCache[$cacheKey])) {
                $diagnostics[] = $this->diagnosticCache[$cacheKey];
                continue;
            }

            $diagnostic = $this->runTargetDiagnostic(
                $target->package(),
                $target->constraint(),
                $workingDirectory,
                $platform
            );
            $this->diagnosticCache[$cacheKey] = $diagnostic;
            $diagnostics[] = $diagnostic;
        }

        return $diagnostics;
    }

    private function runTargetDiagnostic(
        string $package,
        string $constraint,
        string $workingDirectory,
        TargetPlatform $platform
    ): ComposerDiagnostic {
        if (!$this->supportsLockedDiagnostics()) {
            return new ComposerDiagnostic(
                $package,
                $constraint,
                [],
                1,
                '',
                sprintf(
                    'Composer %s does not support locked prohibits diagnostics; Composer %s or newer is required.',
                    $this->composerVersion,
                    self::LOCKED_DIAGNOSTIC_MIN_COMPOSER_VERSION
                )
            );
        }

        $command = [
            'composer',
            'prohibits',
            $package,
            $constraint,
            '--tree',
            '--locked',
        ];
        $command = array_merge($command, self::COMPOSER_SAFETY_OPTIONS, ['--no-interaction']);

        try {
            $process = ($this->processRunner)($command, $workingDirectory);

            return new ComposerDiagnostic(
                $package,
                $constraint,
                $command,
                $process['exit_code'],
                $process['stdout'],
                $process['stderr']
            );
        } catch (\Throwable $exception) {
            return new ComposerDiagnostic(
                $package,
                $constraint,
                $command,
                1,
                '',
                $exception->getMessage()
            );
        }
    }

    private function supportsLockedDiagnostics(): bool
    {
        if ($this->composerVersion === null || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $this->composerVersion) !== 1) {
            return true;
        }

        return version_compare($this->composerVersion, self::LOCKED_DIAGNOSTIC_MIN_COMPOSER_VERSION, '>=');
    }

    private function supportsAbsentExtensionOverrides(?string $composerVersion): bool
    {
        if ($composerVersion === null
            || preg_match('/^(\d+)\.(\d+)/', $composerVersion, $matches) !== 1) {
            return true;
        }

        return version_compare(
            sprintf('%d.%d.0', (int) $matches[1], (int) $matches[2]),
            self::ABSENT_EXTENSION_MIN_COMPOSER_VERSION,
            '>='
        );
    }

    private function diagnosticCacheKey(
        ProjectState $project,
        Scenario $scenario,
        string $package,
        string $constraint,
        TargetPlatform $platform
    ): string {
        return hash('sha256', serialize([
            $project->path(),
            $scenario->targets()->toArray(),
            $package,
            $constraint,
            array_map(
                static fn ($assumption): array => $assumption->toArray(),
                $platform->extensionAssumptions()
            ),
        ]));
    }

    private function targetNeedsDiagnostic(
        ProjectState $project,
        UpgradeRequest $request,
        string $package,
        string $constraint
    ): bool {
        if ($package === 'php') {
            return $constraint === $request->targets()->targetPhp();
        }

        $locked = $project->composerLock()->package($package);

        return $locked === null || !Semver::satisfies($locked->version(), $constraint);
    }
}
