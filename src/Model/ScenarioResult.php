<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ScenarioResult
{
    public Scenario $scenario;
    public int $exitCode;
    public string $stdout;
    public string $stderr;
    public ?ComposerLock $lock;
    public ?string $tempPath;

    public function __construct(Scenario $scenario, int $exitCode, string $stdout, string $stderr, ?ComposerLock $lock = null, ?string $tempPath = null)
    {
        $this->scenario = $scenario;
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->lock = $lock;
        $this->tempPath = $tempPath;
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0 && $this->lock !== null;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->scenario->name,
            'exit_code' => $this->exitCode,
            'succeeded' => $this->succeeded(),
            'stdout_excerpt' => substr($this->stdout, 0, 4000),
            'stderr_excerpt' => substr($this->stderr, 0, 4000),
            'temp_path' => $this->tempPath,
        ];
    }
}
