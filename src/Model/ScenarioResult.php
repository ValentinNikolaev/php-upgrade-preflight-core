<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ScenarioResult
{
    public const FAILURE_SOLVER = 'solver';
    public const FAILURE_OPERATIONAL = 'operational';

    public Scenario $scenario;
    public int $exitCode;
    public string $stdout;
    public string $stderr;
    public ?ComposerLock $lock;
    public ?string $tempPath;
    public ?string $failureType;

    public function __construct(
        Scenario $scenario,
        int $exitCode,
        string $stdout,
        string $stderr,
        ?ComposerLock $lock = null,
        ?string $tempPath = null,
        ?string $failureType = null
    ) {
        if ($failureType !== null && !in_array($failureType, [self::FAILURE_SOLVER, self::FAILURE_OPERATIONAL], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported scenario failure type "%s".', $failureType));
        }

        $this->scenario = $scenario;
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->lock = $lock;
        $this->tempPath = $tempPath;
        $this->failureType = $failureType;
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

    public function toArray(): array
    {
        return [
            'name' => $this->scenario->name,
            'exit_code' => $this->exitCode,
            'succeeded' => $this->succeeded(),
            'failure_type' => $this->failureType,
            'stdout_excerpt' => substr($this->stdout, 0, 4000),
            'stderr_excerpt' => substr($this->stderr, 0, 4000),
            'temp_path' => $this->tempPath,
        ];
    }
}
