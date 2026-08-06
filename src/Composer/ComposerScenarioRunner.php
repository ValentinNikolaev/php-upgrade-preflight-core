<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use Composer\Semver\Semver;
use PhpUpgradePreflight\Core\Filesystem\TemporaryWorkspaceManager;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceManager;
use PhpUpgradePreflight\Core\Model\CandidateLockEvidence;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

final class ComposerScenarioRunner
{
    private const LOCKED_DIAGNOSTIC_MIN_COMPOSER_VERSION = '2.4.0';

    private WorkspaceManager $workspaces;
    private JsonFileReader $reader;
    /** @var \Closure(list<string>, string): array{exit_code: int, stdout: string, stderr: string} */
    private \Closure $processRunner;
    /** @var \Closure(): ?string */
    private \Closure $composerVersionResolver;
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
     */
    public function __construct(
        ?WorkspaceManager $workspaces = null,
        ?JsonFileReader $reader = null,
        ?callable $processRunner = null,
        ?callable $composerVersionResolver = null,
        ?callable $clock = null
    ) {
        $this->workspaces = $workspaces ?? new TemporaryWorkspaceManager();
        $this->reader = $reader ?? new JsonFileReader();
        $this->processRunner = $processRunner === null
            ? \Closure::fromCallable([$this, 'runProcess'])
            : \Closure::fromCallable($processRunner);
        $this->composerVersionResolver = $composerVersionResolver === null
            ? ($processRunner === null ? \Closure::fromCallable([$this, 'detectComposerVersion']) : static fn (): ?string => null)
            : \Closure::fromCallable($composerVersionResolver);
        $this->clock = $clock === null
            ? static fn (): float => microtime(true)
            : \Closure::fromCallable($clock);
    }

    public function run(ProjectState $project, UpgradeRequest $request, Scenario $scenario): ScenarioResult
    {
        $tempPath = null;
        $command = $this->buildCommand($scenario);
        $composerVersion = $this->resolveComposerVersion();
        $durationMs = 0;
        $startedAt = null;

        try {
            $tempPath = $this->workspaces->createFromProject($project->path());
            if (!$scenario->isBaselineValidation()) {
                $this->applyTemporaryComposerChanges($tempPath, $project->path(), $scenario);
            }
            $startedAt = ($this->clock)();
            $process = ($this->processRunner)($command, $tempPath);
            $durationMs = $this->elapsedMilliseconds($startedAt);

            $lock = null;
            $candidateLockEvidence = null;
            $lockPath = $tempPath . DIRECTORY_SEPARATOR . 'composer.lock';
            if ($process['exit_code'] === 0 && is_file($lockPath)) {
                $lock = new ComposerLock($this->reader->read($lockPath));
                $candidateLockEvidence = CandidateLockEvidence::fromFile($lockPath, $lock);
            }

            $failureType = null;
            $diagnostics = [];
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

            if ($failureType === ScenarioResult::FAILURE_SOLVER) {
                $diagnostics = $this->runTargetDiagnostics($project, $request, $scenario, $tempPath);
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
                $diagnostics
            );
        } catch (\Throwable $exception) {
            if ($startedAt !== null && $durationMs === 0) {
                $durationMs = $this->elapsedMilliseconds($startedAt);
            }

            $result = new ScenarioResult(
                $scenario,
                1,
                '',
                $exception->getMessage(),
                null,
                $request->debug() ? $tempPath : null,
                ScenarioResult::FAILURE_OPERATIONAL,
                $composerVersion,
                $command,
                $durationMs
            );
        }

        if (!$request->debug() && $tempPath !== null) {
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
                    $result->diagnostics()
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

    private function detectComposerVersion(): ?string
    {
        $process = new Process(['composer', '--version', '--no-ansi', '--no-interaction'], null, ['COMPOSER_NO_INTERACTION' => '1'], null, 30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());
        if (preg_match('/\bComposer(?:\s+version)?\s+([^\s]+)/i', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
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

    /** @return list<ComposerDiagnostic> */
    private function runTargetDiagnostics(
        ProjectState $project,
        UpgradeRequest $request,
        Scenario $scenario,
        string $workingDirectory
    ): array {
        $diagnostics = [];

        foreach ($scenario->targets()->all() as $target) {
            if (!$this->targetNeedsDiagnostic($project, $request, $target->package(), $target->constraint())) {
                continue;
            }

            $cacheKey = $this->diagnosticCacheKey($project, $scenario, $target->package(), $target->constraint());
            if (isset($this->diagnosticCache[$cacheKey])) {
                $diagnostics[] = $this->diagnosticCache[$cacheKey];
                continue;
            }

            $diagnostic = $this->runTargetDiagnostic(
                $target->package(),
                $target->constraint(),
                $workingDirectory
            );
            $this->diagnosticCache[$cacheKey] = $diagnostic;
            $diagnostics[] = $diagnostic;
        }

        return $diagnostics;
    }

    private function runTargetDiagnostic(string $package, string $constraint, string $workingDirectory): ComposerDiagnostic
    {
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
            '--no-plugins',
            '--no-interaction',
        ];

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

    private function diagnosticCacheKey(
        ProjectState $project,
        Scenario $scenario,
        string $package,
        string $constraint
    ): string {
        return hash('sha256', serialize([
            $project->path(),
            $scenario->targets()->toArray(),
            $package,
            $constraint,
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
