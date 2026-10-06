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
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Support\PathExposurePolicy;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ComposerScenarioRunner
{
    private const COMPLETE_PLATFORM_MIN_COMPOSER_VERSION = '2.2.0';
    private const LOCKED_DIAGNOSTIC_MIN_COMPOSER_VERSION = '2.4.0';
    /**
     * Composer metadata probes (`--version`, `show --platform`) are short local
     * commands and are not covered by the configured scenario or diagnostic
     * timeouts, which bound solver work instead.
     */
    private const METADATA_PROBE_TIMEOUT_SECONDS = 30;
    /** @var list<string> */
    private const COMPOSER_SAFETY_OPTIONS = ['--no-scripts', '--no-plugins'];

    private WorkspaceManager $workspaces;
    private JsonFileReader $reader;
    private ScenarioOutcomeClassifier $classifier;
    private ScenarioWorkspacePreparer $preparer;
    /** @var \Closure(list<string>, string, array<string, string|false>, int): array{exit_code: int, stdout: string, stderr: string} */
    private \Closure $processRunner;
    /** @var \Closure(ComposerExecutionConfiguration): ?string */
    private \Closure $composerVersionResolver;
    /** @var \Closure(list<string>, string, array<string, string|false>): array{exit_code: int, stdout: string, stderr: string} */
    private \Closure $composerVersionProcessRunner;
    /** @var \Closure(): float */
    private \Closure $clock;
    /** @var \Closure(ComposerExecutionConfiguration): ?array<string, string> */
    private \Closure $platformPackageResolver;
    private bool $composerVersionResolved = false;
    private ?string $composerVersion = null;
    private ?string $composerVersionConfigurationKey = null;
    private bool $platformPackagesResolved = false;
    /** @var ?array<string, string> */
    private ?array $platformPackages = null;
    private ?string $platformPackagesConfigurationKey = null;
    /** @var array<string, ComposerDiagnostic> */
    private array $diagnosticCache = [];
    /** @var list<string> */
    private array $probeCleanupUncertainties = [];
    /** @var list<string> */
    private array $candidateLockUncertainties = [];

    /**
     * @param null|callable(list<string>, string, array<string, string|false>, int): array{exit_code: int, stdout: string, stderr: string} $processRunner
     * @param null|callable(ComposerExecutionConfiguration): ?string $composerVersionResolver
     * @param null|callable(): float $clock
     * @param null|callable(list<string>, string, array<string, string|false>): array{exit_code: int, stdout: string, stderr: string} $composerVersionProcessRunner
     * @param null|callable(ComposerExecutionConfiguration): ?array<string, string> $platformPackageResolver
     */
    public function __construct(
        ?WorkspaceManager $workspaces = null,
        ?JsonFileReader $reader = null,
        ?callable $processRunner = null,
        ?callable $composerVersionResolver = null,
        ?callable $clock = null,
        ?callable $composerVersionProcessRunner = null,
        ?callable $platformPackageResolver = null
    ) {
        $this->workspaces = $workspaces ?? new TemporaryWorkspaceManager();
        $this->reader = $reader ?? new JsonFileReader();
        $this->classifier = new ScenarioOutcomeClassifier();
        $this->preparer = new ScenarioWorkspacePreparer();
        $this->processRunner = $processRunner === null
            ? \Closure::fromCallable([$this, 'runProcess'])
            : \Closure::fromCallable($processRunner);
        $this->composerVersionProcessRunner = $composerVersionProcessRunner === null
            ? \Closure::fromCallable([$this, 'runVersionProcess'])
            : \Closure::fromCallable($composerVersionProcessRunner);
        $this->composerVersionResolver = $composerVersionResolver === null
            ? (self::defaultVersionResolver($processRunner, $composerVersionProcessRunner)
                ?? \Closure::fromCallable([$this, 'detectComposerVersion']))
            : \Closure::fromCallable($composerVersionResolver);
        $this->clock = $clock === null
            ? static fn (): float => microtime(true)
            : \Closure::fromCallable($clock);
        $this->platformPackageResolver = $platformPackageResolver === null
            ? \Closure::fromCallable([$this, 'detectComposerPlatformPackages'])
            : \Closure::fromCallable($platformPackageResolver);
    }

    /**
     * Resolves the version resolver used when the caller supplied none.
     *
     * Version detection spawns a real Composer process. That is only acceptable
     * when the caller kept the production process adapters, or explicitly opted
     * in by supplying a metadata process runner. A caller that replaced the
     * scenario process runner without supplying a metadata process runner gets a
     * disabled resolver instead of an unexpected child process.
     *
     * Returns null when the runner's own detector should be used.
     */
    private static function defaultVersionResolver(
        ?callable $processRunner,
        ?callable $versionProcessRunner
    ): ?\Closure {
        if ($processRunner === null || $versionProcessRunner !== null) {
            return null;
        }

        return static fn (): ?string => null;
    }

    public function run(
        ProjectState $project,
        UpgradeRequest $request,
        Scenario $scenario,
        ?TargetPlatform $platform = null
    ): ScenarioResult {
        $platform = $platform ?? TargetPlatform::fromRequest($request, $project);
        $tempPath = null;
        $repositoryPaths = PathExposurePolicy::composerRepositoryReferences(
            $project->composerJson()->data(),
            $project->path()
        );
        $execution = $request->composerExecution();
        $command = $this->buildCommand($scenario, $execution);
        $composerVersion = $this->resolveComposerVersion($execution);
        if ($execution->matchesVersion($composerVersion) === false) {
            return $this->operationalResult(
                $scenario,
                $composerVersion,
                $this->safeCommand($command, $execution),
                sprintf(
                    'Composer %s does not match the configured expected version constraint %s.',
                    $composerVersion,
                    $execution->expectedVersion()
                )
            );
        }
        [$platformFailure, $analyzerPlatformPackages] = $this->platformSimulationReadiness(
            $scenario,
            $platform,
            $execution,
            $composerVersion
        );
        if ($platformFailure !== null) {
            return $this->operationalResult(
                $scenario,
                $composerVersion,
                $this->safeCommand($command, $execution),
                $platformFailure
            );
        }
        $startedAt = ($this->clock)();
        $phase = ScenarioOutcomeClassifier::PHASE_WORKSPACE;
        $cleanupFailedDuringCreation = false;

        try {
            $tempPath = $this->workspaces->createFromProject($project->path());
            $this->preparer->seedProjectState($tempPath, $project);
            $phase = ScenarioOutcomeClassifier::PHASE_PREPARATION;
            $candidateManifest = $project->composerJson();
            if (!$scenario->isBaselineValidation()) {
                $candidateManifest = $this->preparer->applyTemporaryComposerChanges(
                    $tempPath,
                    $project,
                    $scenario,
                    $platform,
                    $analyzerPlatformPackages
                );
            }
            // The restricted Composer state is analyzer-owned workspace preparation:
            // a failure here happens before any Composer process exists.
            $environment = $this->preparer->processEnvironment($execution, $tempPath);
            $phase = ScenarioOutcomeClassifier::PHASE_PROCESS;
            $process = ($this->processRunner)(
                $command,
                $tempPath,
                $environment,
                $execution->scenarioTimeoutSeconds()
            );
            $process = $this->sanitizeProcessResult(
                $process,
                $project->path(),
                $tempPath,
                $repositoryPaths,
                $execution
            );
            $phase = ScenarioOutcomeClassifier::PHASE_LOCKFILE;
            [$lock, $candidateLockEvidence, $candidateProjectState] = $this->readCandidateLock(
                $tempPath,
                $project,
                $candidateManifest,
                $process['exit_code'] === 0
            );

            $classification = $this->classifier->classifyProcessResult(
                $process,
                $lock !== null,
                $scenario,
                $execution
            );
            $diagnostics = $classification->isSolverFailure()
                ? $this->runTargetDiagnostics(
                    $project,
                    $request,
                    $scenario,
                    $tempPath,
                    $platform,
                    $repositoryPaths,
                    $execution,
                    $environment
                )
                : [];

            $result = new ScenarioResult(
                $scenario,
                $process['exit_code'],
                $process['stdout'],
                $process['stderr'],
                $lock,
                $request->debug() ? $tempPath : null,
                $classification->failureType(),
                $composerVersion,
                $this->safeCommand($command, $execution),
                0,
                $candidateLockEvidence,
                $diagnostics,
                $classification->outcome(),
                $request->debug(),
                $candidateProjectState
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof WorkspaceCleanupException) {
                $tempPath = $exception->workspacePath();
                $cleanupFailedDuringCreation = true;
            }

            [$stdout, $stderr, $exitCode] = $this->exceptionExecutionEvidence($exception);
            $result = new ScenarioResult(
                $scenario,
                $exitCode,
                $this->redactExecutionText($stdout, $project->path(), $tempPath, $repositoryPaths, $execution),
                $this->redactExecutionText($stderr, $project->path(), $tempPath, $repositoryPaths, $execution),
                null,
                $request->debug() || $cleanupFailedDuringCreation ? $tempPath : null,
                ScenarioResult::FAILURE_OPERATIONAL,
                $composerVersion,
                $this->safeCommand($command, $execution),
                0,
                null,
                [],
                $this->classifier->classifyException($exception, $phase)->outcome(),
                $request->debug()
            );
        }

        if (!$request->debug() && $tempPath !== null && !$cleanupFailedDuringCreation) {
            $result = $this->removeScenarioWorkspace(
                $result,
                $scenario,
                $tempPath,
                $project->path(),
                $repositoryPaths,
                $request->debug()
            );
        }

        return $this->withDuration($result, $this->elapsedMilliseconds($startedAt), $request->debug());
    }

    public function resetDiagnosticCache(): void
    {
        $this->diagnosticCache = [];
    }

    public function resetAnalysisCaches(): void
    {
        $this->resetDiagnosticCache();
        $this->composerVersionResolved = false;
        $this->composerVersion = null;
        $this->composerVersionConfigurationKey = null;
        $this->platformPackagesResolved = false;
        $this->platformPackages = null;
        $this->platformPackagesConfigurationKey = null;
        $this->probeCleanupUncertainties = [];
        $this->candidateLockUncertainties = [];
    }

    /**
     * Metadata probe workspaces that could not be removed. Each entry is a
     * shareable uncertainty: the analyzer left state behind, so any Composer
     * version or platform inventory derived from that probe is suspect. The
     * list is scoped to the currently cached probe answers and is cleared by
     * {@see self::resetAnalysisCaches()}.
     *
     * @return list<string>
     */
    public function probeCleanupUncertainties(): array
    {
        return array_values(array_unique($this->probeCleanupUncertainties));
    }

    /**
     * Candidate-lock entries no scenario could index. Every direct scenario and every staged attempt
     * reads its candidate lock through this runner and then discards it with its workspace, so the
     * omissions are collected here to reach the report at all: the published candidate package count
     * and the package changes derived from that lock exclude the skipped entries while the recorded
     * hash still covers the whole file. The list spans the current analysis and is cleared by
     * {@see self::resetAnalysisCaches()}.
     *
     * @return list<string>
     */
    public function candidateLockUncertainties(): array
    {
        return array_values(array_unique($this->candidateLockUncertainties));
    }

    /**
     * Decides whether Composer may be asked to simulate the requested target
     * platform, and resolves the analyzer inventory that decision needed.
     *
     * @return array{?string, ?array<string, string>} the blocking failure message
     *         when the simulation must not be attempted, and the analyzer platform
     *         inventory the scenario workspace should be built against
     */
    private function platformSimulationReadiness(
        Scenario $scenario,
        TargetPlatform $platform,
        ComposerExecutionConfiguration $execution,
        ?string $composerVersion
    ): array {
        if ($scenario->isBaselineValidation() && !$platform->isCompleteProfile()) {
            return [null, null];
        }

        $capabilityFailure = $this->platformCapabilityFailure($platform, $composerVersion);
        if ($capabilityFailure !== null) {
            return [$capabilityFailure, null];
        }

        if (!$platform->needsToolchainValidation()) {
            return [null, null];
        }

        $analyzerPlatformPackages = $this->resolvePlatformPackages($execution);
        if ($analyzerPlatformPackages === null) {
            return [
                $platform->isCompleteProfile()
                    ? 'Composer platform inventory could not be determined; the complete target-platform profile was not weakened to partial coverage.'
                    : 'Composer platform inventory could not be determined, so toolchain-bound target-platform values could not be validated.',
                null,
            ];
        }

        return [$platform->toolchainValidationFailure($analyzerPlatformPackages), $analyzerPlatformPackages];
    }

    /**
     * Reads the candidate lockfile a resolved scenario produced in its workspace.
     *
     * @return array{?ComposerLock, ?CandidateLockEvidence, ?ProjectState}
     */
    private function readCandidateLock(
        string $tempPath,
        ProjectState $project,
        ComposerJson $candidateManifest,
        bool $resolved
    ): array {
        $lockPath = $tempPath . DIRECTORY_SEPARATOR . 'composer.lock';
        if (!$resolved || !is_file($lockPath)) {
            return [null, null, null];
        }

        $lock = new ComposerLock($this->reader->read($lockPath), array_keys($candidateManifest->rootRequirements()));
        $this->candidateLockUncertainties = array_merge(
            $this->candidateLockUncertainties,
            $lock->unusableCandidatePackageUncertainties()
        );

        return [
            $lock,
            CandidateLockEvidence::fromFile($lockPath, $lock),
            new ProjectState($project->path(), $candidateManifest, $lock),
        ];
    }

    /** @return list<string> */
    private function buildCommand(Scenario $scenario, ComposerExecutionConfiguration $execution): array
    {
        if ($scenario->isBaselineValidation()) {
            return array_merge([
                $execution->executable(),
                'validate',
                '--check-lock',
                '--no-check-publish',
            ], self::COMPOSER_SAFETY_OPTIONS, [
                '--no-interaction',
            ]);
        }

        $command = [$execution->executable(), 'update'];

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
     * @param array<string, string|false> $environment
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(
        array $command,
        string $workingDirectory,
        array $environment,
        int $timeoutSeconds
    ): array {
        $process = new Process(
            $command,
            $workingDirectory,
            $environment,
            null,
            $timeoutSeconds
        );
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function detectComposerVersion(?ComposerExecutionConfiguration $execution = null): ?string
    {
        $execution = $execution ?? ComposerExecutionConfiguration::compatible();
        $process = $this->runComposerMetadataCommand(array_merge(
            [$execution->executable(), '--version', '--no-ansi'],
            self::COMPOSER_SAFETY_OPTIONS,
            ['--no-interaction']
        ), $execution);

        if ($process['exit_code'] !== 0) {
            return null;
        }

        $output = trim($process['stdout'] . "\n" . $process['stderr']);
        if (preg_match('/\bComposer(?:\s+version)?\s+([^\s]+)/i', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** @return ?array<string, string> */
    private function detectComposerPlatformPackages(?ComposerExecutionConfiguration $execution = null): ?array
    {
        $execution = $execution ?? ComposerExecutionConfiguration::compatible();
        $process = $this->runComposerMetadataCommand(array_merge(
            [$execution->executable(), 'show', '--platform', '--format=json'],
            self::COMPOSER_SAFETY_OPTIONS,
            ['--no-interaction']
        ), $execution);
        if ($process['exit_code'] !== 0) {
            return null;
        }

        try {
            $decoded = json_decode($process['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $inventory = $decoded['platform'] ?? $decoded['installed'] ?? null;
        if (!is_array($inventory)) {
            return null;
        }

        $packages = [];
        foreach ($inventory as $package) {
            if (!is_array($package)
                || !isset($package['name'], $package['version'])
                || !is_string($package['name'])
                || !is_string($package['version'])
            ) {
                return null;
            }
            $name = strtolower(trim($package['name']));
            if (TargetPlatform::isSupportedPackageName($name)) {
                $packages[$name] = trim($package['version']);
            }
        }
        ksort($packages, SORT_STRING);

        return $packages;
    }

    /**
     * @param list<string> $command
     * @param array<string, string|false> $environment
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runVersionProcess(array $command, string $workingDirectory, array $environment): array
    {
        $process = new Process(
            $command,
            $workingDirectory,
            $environment,
            null,
            self::METADATA_PROBE_TIMEOUT_SECONDS
        );
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /**
     * @param list<string> $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runComposerMetadataCommand(
        array $command,
        ComposerExecutionConfiguration $execution
    ): array {
        $workingDirectory = $this->createComposerProbeDirectory();

        try {
            $environment = $this->preparer->processEnvironment($execution, $workingDirectory);
            if (!$execution->isRestricted()) {
                $environment['COMPOSER'] = false;
                $environment['COMPOSER_HOME'] = $workingDirectory;
            }

            return ($this->composerVersionProcessRunner)(
                $command,
                $workingDirectory,
                $environment
            );
        } finally {
            $this->removeComposerProbeDirectory($workingDirectory);
        }
    }

    /**
     * Removes a probe workspace through the injected workspace manager so it
     * obeys the same cleanup contract as scenario workspaces.
     *
     * A cleanup failure must never replace the probe result or vanish: the
     * probe answer is still usable, but the leaked state becomes a recorded
     * uncertainty.
     */
    private function removeComposerProbeDirectory(string $path): void
    {
        try {
            $this->workspaces->remove($path);
        } catch (\Throwable $exception) {
            $this->probeCleanupUncertainties[] = sprintf(
                'Composer metadata probe workspace cleanup failed, so analyzer-owned temporary state was left behind: %s',
                PathExposurePolicy::redactComposerText($exception->getMessage(), null, $path)
            );
        }
    }

    private function createComposerProbeDirectory(): string
    {
        $temporaryRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $path = $temporaryRoot
                . DIRECTORY_SEPARATOR
                . 'php-upgrade-preflight-composer-probe-'
                . bin2hex(random_bytes(8));
            if (@mkdir($path, 0700)) {
                return $path;
            }
        }

        throw new \RuntimeException('Unable to create an isolated Composer platform probe directory.');
    }

    private function resolveComposerVersion(ComposerExecutionConfiguration $execution): ?string
    {
        $key = $execution->runtimeCacheKey();
        if ($this->composerVersionResolved && $this->composerVersionConfigurationKey === $key) {
            return $this->composerVersion;
        }

        $this->composerVersionResolved = true;
        $this->composerVersionConfigurationKey = $key;

        try {
            $version = ($this->composerVersionResolver)($execution);
            $this->composerVersion = $version === null || trim($version) === '' ? null : trim($version);
        } catch (\Throwable $exception) {
            $this->composerVersion = null;
        }

        return $this->composerVersion;
    }

    /** @return ?array<string, string> */
    private function resolvePlatformPackages(ComposerExecutionConfiguration $execution): ?array
    {
        $key = $execution->runtimeCacheKey();
        if ($this->platformPackagesResolved && $this->platformPackagesConfigurationKey === $key) {
            return $this->platformPackages;
        }

        $this->platformPackagesResolved = true;
        $this->platformPackagesConfigurationKey = $key;
        try {
            $packages = ($this->platformPackageResolver)($execution);
            if (!is_array($packages)) {
                return $this->platformPackages = null;
            }

            $normalized = [];
            foreach ($packages as $name => $version) {
                if (!is_string($name) || !is_string($version)) {
                    return $this->platformPackages = null;
                }
                $name = strtolower(trim($name));
                if (TargetPlatform::isSupportedPackageName($name)) {
                    $normalized[$name] = trim($version);
                }
            }
            ksort($normalized, SORT_STRING);

            return $this->platformPackages = $normalized;
        } catch (\Throwable) {
            return $this->platformPackages = null;
        }
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return max(0, (int) round(((float) ($this->clock)() - $startedAt) * 1000));
    }

    private function withDuration(ScenarioResult $result, int $durationMs, bool $exposeTempPath): ScenarioResult
    {
        return new ScenarioResult(
            $result->scenario(),
            $result->exitCode(),
            $result->stdout(),
            $result->stderr(),
            $result->lock(),
            $result->tempPath(),
            $result->failureType(),
            $result->composerVersion(),
            $result->command(),
            $durationMs,
            $result->candidateLockEvidence(),
            $result->diagnostics(),
            $result->outcome(),
            $exposeTempPath,
            $result->candidateProjectState()
        );
    }

    /**
     * Extracts the execution evidence a failed scenario can still publish.
     *
     * @return array{string, string, int}
     */
    private function exceptionExecutionEvidence(\Throwable $exception): array
    {
        if (!$exception instanceof ProcessTimedOutException) {
            return ['', $exception->getMessage(), 1];
        }

        $timedOutProcess = $exception->getProcess();

        try {
            $stdout = $timedOutProcess->getOutput();
            $stderr = $timedOutProcess->getErrorOutput();
        } catch (\Throwable) {
            $stdout = '';
            $stderr = '';
        }

        return [
            $stdout,
            trim($stderr . PHP_EOL . $exception->getMessage()),
            $timedOutProcess->getExitCode() ?? 1,
        ];
    }

    /**
     * Removes a non-debug scenario workspace, turning a cleanup failure into a
     * structured outcome that keeps the collected evidence.
     *
     * @param list<string> $repositoryPaths
     */
    private function removeScenarioWorkspace(
        ScenarioResult $result,
        Scenario $scenario,
        string $tempPath,
        string $projectPath,
        array $repositoryPaths,
        bool $debug
    ): ScenarioResult {
        try {
            $this->workspaces->remove($tempPath);

            return $result;
        } catch (\Throwable $exception) {
            return new ScenarioResult(
                $scenario,
                $result->exitCode(),
                $result->stdout(),
                trim($result->stderr() . PHP_EOL . sprintf(
                    'Temporary workspace cleanup failed: %s',
                    PathExposurePolicy::redactComposerText(
                        $exception->getMessage(),
                        $projectPath,
                        $tempPath,
                        $repositoryPaths
                    )
                )),
                null,
                $tempPath,
                ScenarioResult::FAILURE_OPERATIONAL,
                $result->composerVersion(),
                $result->command(),
                0,
                $result->candidateLockEvidence(),
                $result->diagnostics(),
                ScenarioResult::OUTCOME_CLEANUP_FAILURE,
                $debug
            );
        }
    }

    /**
     * @param array{exit_code: int, stdout: string, stderr: string} $process
     * @param list<string> $repositoryPaths
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function sanitizeProcessResult(
        array $process,
        string $projectPath,
        string $workspacePath,
        array $repositoryPaths,
        ComposerExecutionConfiguration $execution
    ): array {
        return [
            'exit_code' => $process['exit_code'],
            'stdout' => $this->redactExecutionText(
                $process['stdout'],
                $projectPath,
                $workspacePath,
                $repositoryPaths,
                $execution
            ),
            'stderr' => $this->redactExecutionText(
                $process['stderr'],
                $projectPath,
                $workspacePath,
                $repositoryPaths,
                $execution
            ),
        ];
    }

    /**
     * @param list<string> $repositoryPaths
     * @param array<string, string|false> $environment
     * @return list<ComposerDiagnostic>
     */
    private function runTargetDiagnostics(
        ProjectState $project,
        UpgradeRequest $request,
        Scenario $scenario,
        string $workingDirectory,
        TargetPlatform $platform,
        array $repositoryPaths,
        ComposerExecutionConfiguration $execution,
        array $environment
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
                $platform,
                $project->path(),
                $repositoryPaths,
                $execution,
                $environment
            );
            $this->diagnosticCache[$cacheKey] = $diagnostic;
            $diagnostics[] = $diagnostic;
        }

        return $diagnostics;
    }

    /**
     * @param list<string> $repositoryPaths
     * @param array<string, string|false> $environment
     */
    private function runTargetDiagnostic(
        string $package,
        string $constraint,
        string $workingDirectory,
        TargetPlatform $platform,
        string $projectPath,
        array $repositoryPaths,
        ComposerExecutionConfiguration $execution,
        array $environment
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
                ),
                ScenarioResult::OUTCOME_PROCESS_FAILURE
            );
        }

        $command = [
            $execution->executable(),
            'prohibits',
            $package,
            $constraint,
            '--tree',
            '--locked',
        ];
        $command = array_merge($command, self::COMPOSER_SAFETY_OPTIONS, ['--no-interaction']);

        try {
            $process = ($this->processRunner)(
                $command,
                $workingDirectory,
                $environment,
                $execution->diagnosticTimeoutSeconds()
            );
            $process = $this->sanitizeProcessResult(
                $process,
                $projectPath,
                $workingDirectory,
                $repositoryPaths,
                $execution
            );

            return new ComposerDiagnostic(
                $package,
                $constraint,
                $this->safeCommand($command, $execution),
                $process['exit_code'],
                $process['stdout'],
                $process['stderr'],
                $this->classifier->classifyDiagnosticResult($process, $execution)
            );
        } catch (\Throwable $exception) {
            return new ComposerDiagnostic(
                $package,
                $constraint,
                $this->safeCommand($command, $execution),
                1,
                '',
                $this->redactExecutionText(
                    $exception->getMessage(),
                    $projectPath,
                    $workingDirectory,
                    $repositoryPaths,
                    $execution
                ),
                $this->classifier->classifyDiagnosticException($exception)
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

    private function platformCapabilityFailure(TargetPlatform $platform, ?string $composerVersion): ?string
    {
        if (!$platform->isCompleteProfile() && !$platform->hasAbsentPlatformPackages()) {
            return null;
        }

        if ($composerVersion === null || preg_match('/^(\d+)\.(\d+)/', $composerVersion, $matches) !== 1) {
            return $platform->isCompleteProfile()
                ? 'Composer version could not be determined; Composer 2.2.0 or newer is required for a complete target-platform profile, which was not weakened to partial coverage.'
                : null;
        }

        $normalized = sprintf('%d.%d.0', (int) $matches[1], (int) $matches[2]);
        if (version_compare($normalized, self::COMPLETE_PLATFORM_MIN_COMPOSER_VERSION, '>=')) {
            return null;
        }

        return sprintf(
            'Composer %s cannot hide absent platform packages; Composer %s or newer is required%s.',
            $composerVersion,
            self::COMPLETE_PLATFORM_MIN_COMPOSER_VERSION,
            $platform->isCompleteProfile()
                ? ' for a complete target-platform profile, which was not weakened to partial coverage'
                : ''
        );
    }

    /** @param list<string> $command */
    private function operationalResult(
        Scenario $scenario,
        ?string $composerVersion,
        array $command,
        string $message
    ): ScenarioResult {
        return new ScenarioResult(
            $scenario,
            1,
            '',
            $message,
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

    private function diagnosticCacheKey(
        ProjectState $project,
        Scenario $scenario,
        string $package,
        string $constraint,
        TargetPlatform $platform
    ): string {
        return hash('sha256', serialize([
            $project->path(),
            $project->composerJson()->data(),
            $project->composerLock()->data(),
            $scenario->targets()->toArray(),
            $package,
            $constraint,
            array_map(
                static fn ($assumption): array => $assumption->toArray(),
                $platform->extensionAssumptions()
            ),
            $platform->profileDigest(),
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

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function safeCommand(array $command, ComposerExecutionConfiguration $execution): array
    {
        if ($command !== [] && $execution->executable() !== 'composer') {
            $command[0] = '[COMPOSER_EXECUTABLE]';
        }

        return $command;
    }

    /** @param list<string> $repositoryPaths */
    private function redactExecutionText(
        string $value,
        ?string $projectPath,
        ?string $workspacePath,
        array $repositoryPaths,
        ComposerExecutionConfiguration $execution
    ): string {
        $value = PathExposurePolicy::redactComposerText(
            $value,
            $projectPath,
            $workspacePath,
            $repositoryPaths
        );
        if ($execution->executable() !== 'composer') {
            $value = PathExposurePolicy::redactPaths($value, [
                $execution->executable() => '[COMPOSER_EXECUTABLE]',
            ]);
        }

        return $value;
    }
}
