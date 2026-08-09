<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

use PhpUpgradePreflight\Core\Support\OutputExcerpt;
use PhpUpgradePreflight\Core\Support\PathExposurePolicy;

final class ScenarioResult
{
    public const FAILURE_SOLVER = 'solver';
    public const FAILURE_OPERATIONAL = 'operational';
    public const FAILURE_VALIDATION = 'validation';

    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_SOLVER_FAILURE = 'solver_failure';
    public const OUTCOME_VALIDATION_FAILURE = 'validation_failure';
    public const OUTCOME_COMPOSER_MISSING = 'composer_missing';
    public const OUTCOME_TIMEOUT = 'timeout';
    public const OUTCOME_INVALID_JSON = 'invalid_json';
    public const OUTCOME_LOCKFILE_MISSING = 'lockfile_missing';
    public const OUTCOME_PROCESS_FAILURE = 'process_failure';
    public const OUTCOME_CLEANUP_FAILURE = 'cleanup_failure';
    public const OUTCOME_WORKSPACE_FAILURE = 'workspace_failure';

    private Scenario $scenario;
    private int $exitCode;
    private string $stdout;
    private string $stderr;
    private ?ComposerLock $lock;
    private ?string $tempPath;
    private ?string $failureType;
    private string $outcome;
    private ?string $composerVersion;
    /** @var list<string> */
    private array $command;
    private int $durationMs;
    private ?CandidateLockEvidence $candidateLockEvidence;
    /** @var list<ComposerDiagnostic> */
    private array $diagnostics;
    private bool $exposeTempPath;

    public function __construct(
        Scenario $scenario,
        int $exitCode,
        string $stdout,
        string $stderr,
        ?ComposerLock $lock = null,
        ?string $tempPath = null,
        ?string $failureType = null,
        ?string $composerVersion = null,
        array $command = [],
        int $durationMs = 0,
        ?CandidateLockEvidence $candidateLockEvidence = null,
        array $diagnostics = [],
        ?string $outcome = null,
        bool $exposeTempPath = false
    ) {
        if ($failureType !== null && !in_array($failureType, [self::FAILURE_SOLVER, self::FAILURE_OPERATIONAL, self::FAILURE_VALIDATION], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported scenario failure type "%s".', $failureType));
        }

        if ($durationMs < 0) {
            throw new \InvalidArgumentException('Scenario duration cannot be negative.');
        }

        foreach ($command as $argument) {
            if (!is_string($argument)) {
                throw new \InvalidArgumentException('Scenario command arguments must be strings.');
            }
        }

        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof ComposerDiagnostic) {
                throw new \InvalidArgumentException('Scenario diagnostics must be ComposerDiagnostic instances.');
            }
        }

        $outcome = $outcome ?? self::inferOutcome($exitCode, $lock, $failureType);
        if (!in_array($outcome, self::supportedOutcomes(), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported scenario outcome "%s".', $outcome));
        }

        $expectedFailureType = self::failureTypeForOutcome($outcome);
        if ($failureType !== $expectedFailureType) {
            throw new \InvalidArgumentException(sprintf(
                'Scenario outcome "%s" requires failure type %s.',
                $outcome,
                $expectedFailureType === null ? 'null' : sprintf('"%s"', $expectedFailureType)
            ));
        }

        if ($outcome === self::OUTCOME_SUCCESS && ($exitCode !== 0 || $lock === null)) {
            throw new \InvalidArgumentException('A successful scenario outcome requires exit code 0, a lockfile, and no failure type.');
        }

        if ($outcome !== self::OUTCOME_SUCCESS && $lock !== null) {
            throw new \InvalidArgumentException('A failed scenario outcome cannot contain a candidate lock.');
        }

        $this->scenario = $scenario;
        $this->exitCode = $exitCode;
        $this->stdout = PathExposurePolicy::redactComposerText($stdout, null, $tempPath);
        $this->stderr = PathExposurePolicy::redactComposerText($stderr, null, $tempPath);
        $this->lock = $lock;
        $this->tempPath = $tempPath;
        $this->failureType = $failureType;
        $this->outcome = $outcome;
        $this->composerVersion = $composerVersion;
        $this->command = array_values($command);
        $this->durationMs = $durationMs;
        $this->candidateLockEvidence = $candidateLockEvidence ?? ($lock === null ? null : CandidateLockEvidence::fromLock($lock));
        $this->diagnostics = array_values($diagnostics);
        $this->exposeTempPath = $exposeTempPath;
    }

    public function scenario(): Scenario
    {
        return $this->scenario;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function stdout(): string
    {
        return $this->stdout;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }

    public function lock(): ?ComposerLock
    {
        return $this->lock;
    }

    public function tempPath(): ?string
    {
        return $this->tempPath;
    }

    public function failureType(): ?string
    {
        return $this->failureType;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function composerVersion(): ?string
    {
        return $this->composerVersion;
    }

    /** @return list<string> */
    public function command(): array
    {
        return $this->command;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }

    public function candidateLockEvidence(): ?CandidateLockEvidence
    {
        return $this->candidateLockEvidence;
    }

    /** @return list<ComposerDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function succeeded(): bool
    {
        return $this->outcome === self::OUTCOME_SUCCESS;
    }

    public function isSolverFailure(): bool
    {
        return !$this->succeeded() && $this->failureType === self::FAILURE_SOLVER;
    }

    public function isOperationalFailure(): bool
    {
        return !$this->succeeded() && $this->failureType === self::FAILURE_OPERATIONAL;
    }

    public function isValidationFailure(): bool
    {
        return !$this->succeeded() && $this->failureType === self::FAILURE_VALIDATION;
    }

    /** @return array{name: string, composer_version: ?string, command: list<string>, duration_ms: int, exit_code: int, succeeded: bool, outcome: string, failure_type: ?string, stdout_excerpt: string, stderr_excerpt: string, candidate_lock: ?array{sha256: string, content_hash: ?string, package_count: int}, diagnostics: list<array{package: string, constraint: string, command: list<string>, exit_code: int, stdout_excerpt: string, stderr_excerpt: string}>, temp_path: ?string} */
    public function toArray(): array
    {
        $command = array_map(
            fn (string $argument): string => PathExposurePolicy::redactComposerText($argument, null, $this->tempPath),
            $this->command
        );
        $diagnostics = array_map(function (ComposerDiagnostic $diagnostic): array {
            $canonical = $diagnostic->toArray();
            $canonical['command'] = array_map(
                fn (string $argument): string => PathExposurePolicy::redactComposerText($argument, null, $this->tempPath),
                $canonical['command']
            );
            $canonical['stdout_excerpt'] = PathExposurePolicy::redactComposerText(
                $canonical['stdout_excerpt'],
                null,
                $this->tempPath
            );
            $canonical['stderr_excerpt'] = PathExposurePolicy::redactComposerText(
                $canonical['stderr_excerpt'],
                null,
                $this->tempPath
            );

            return $canonical;
        }, $this->diagnostics);

        return [
            'name' => $this->scenario->name(),
            'composer_version' => $this->composerVersion,
            'command' => $command,
            'duration_ms' => $this->durationMs,
            'exit_code' => $this->exitCode,
            'succeeded' => $this->succeeded(),
            'outcome' => $this->outcome,
            'failure_type' => $this->failureType,
            'stdout_excerpt' => OutputExcerpt::bounded($this->stdout),
            'stderr_excerpt' => OutputExcerpt::bounded($this->stderr),
            'candidate_lock' => $this->candidateLockEvidence === null ? null : $this->candidateLockEvidence->toArray(),
            'diagnostics' => $diagnostics,
            'temp_path' => PathExposurePolicy::workspaceForReport($this->tempPath, $this->exposeTempPath),
        ];
    }

    /** @return list<string> */
    public static function supportedOutcomes(): array
    {
        return [
            self::OUTCOME_SUCCESS,
            self::OUTCOME_SOLVER_FAILURE,
            self::OUTCOME_VALIDATION_FAILURE,
            self::OUTCOME_COMPOSER_MISSING,
            self::OUTCOME_TIMEOUT,
            self::OUTCOME_INVALID_JSON,
            self::OUTCOME_LOCKFILE_MISSING,
            self::OUTCOME_PROCESS_FAILURE,
            self::OUTCOME_CLEANUP_FAILURE,
            self::OUTCOME_WORKSPACE_FAILURE,
        ];
    }

    private static function inferOutcome(int $exitCode, ?ComposerLock $lock, ?string $failureType): string
    {
        if ($exitCode === 0 && $lock !== null && $failureType === null) {
            return self::OUTCOME_SUCCESS;
        }

        if ($failureType === self::FAILURE_SOLVER) {
            return self::OUTCOME_SOLVER_FAILURE;
        }

        if ($failureType === self::FAILURE_VALIDATION) {
            return self::OUTCOME_VALIDATION_FAILURE;
        }

        return self::OUTCOME_PROCESS_FAILURE;
    }

    private static function failureTypeForOutcome(string $outcome): ?string
    {
        if ($outcome === self::OUTCOME_SUCCESS) {
            return null;
        }

        if ($outcome === self::OUTCOME_SOLVER_FAILURE) {
            return self::FAILURE_SOLVER;
        }

        if ($outcome === self::OUTCOME_VALIDATION_FAILURE) {
            return self::FAILURE_VALIDATION;
        }

        return self::FAILURE_OPERATIONAL;
    }
}
