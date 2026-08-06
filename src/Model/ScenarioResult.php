<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

use PhpUpgradePreflight\Core\Support\OutputExcerpt;

final class ScenarioResult
{
    public const FAILURE_SOLVER = 'solver';
    public const FAILURE_OPERATIONAL = 'operational';
    public const FAILURE_VALIDATION = 'validation';

    private Scenario $scenario;
    private int $exitCode;
    private string $stdout;
    private string $stderr;
    private ?ComposerLock $lock;
    private ?string $tempPath;
    private ?string $failureType;
    private ?string $composerVersion;
    /** @var list<string> */
    private array $command;
    private int $durationMs;
    private ?CandidateLockEvidence $candidateLockEvidence;
    /** @var list<ComposerDiagnostic> */
    private array $diagnostics;

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
        array $diagnostics = []
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

        $this->scenario = $scenario;
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->lock = $lock;
        $this->tempPath = $tempPath;
        $this->failureType = $failureType;
        $this->composerVersion = $composerVersion;
        $this->command = array_values($command);
        $this->durationMs = $durationMs;
        $this->candidateLockEvidence = $candidateLockEvidence ?? ($lock === null ? null : CandidateLockEvidence::fromLock($lock));
        $this->diagnostics = array_values($diagnostics);
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
        return $this->exitCode === 0 && $this->lock !== null;
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

    /** @return array{name: string, composer_version: ?string, command: list<string>, duration_ms: int, exit_code: int, succeeded: bool, failure_type: ?string, stdout_excerpt: string, stderr_excerpt: string, candidate_lock: ?array{sha256: string, content_hash: ?string, package_count: int}, diagnostics: list<array{package: string, constraint: string, command: list<string>, exit_code: int, stdout_excerpt: string, stderr_excerpt: string}>, temp_path: ?string} */
    public function toArray(): array
    {
        return [
            'name' => $this->scenario->name(),
            'composer_version' => $this->composerVersion,
            'command' => $this->command,
            'duration_ms' => $this->durationMs,
            'exit_code' => $this->exitCode,
            'succeeded' => $this->succeeded(),
            'failure_type' => $this->failureType,
            'stdout_excerpt' => OutputExcerpt::bounded($this->stdout),
            'stderr_excerpt' => OutputExcerpt::bounded($this->stderr),
            'candidate_lock' => $this->candidateLockEvidence === null ? null : $this->candidateLockEvidence->toArray(),
            'diagnostics' => array_map(static fn (ComposerDiagnostic $diagnostic): array => $diagnostic->toArray(), $this->diagnostics),
            'temp_path' => $this->tempPath,
        ];
    }
}
