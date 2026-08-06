<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

use PhpUpgradePreflight\Core\Support\OutputExcerpt;

final class ComposerDiagnostic
{
    private string $package;
    private string $constraint;
    /** @var list<string> */
    private array $command;
    private int $exitCode;
    private string $stdout;
    private string $stderr;

    /** @param list<string> $command */
    public function __construct(
        string $package,
        string $constraint,
        array $command,
        int $exitCode,
        string $stdout,
        string $stderr
    ) {
        foreach ($command as $argument) {
            if (!is_string($argument)) {
                throw new \InvalidArgumentException('Composer diagnostic command arguments must be strings.');
            }
        }

        $this->package = $package;
        $this->constraint = $constraint;
        $this->command = array_values($command);
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function constraint(): string
    {
        return $this->constraint;
    }

    /** @return list<string> */
    public function command(): array
    {
        return $this->command;
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

    /** @return array{package: string, constraint: string, command: list<string>, exit_code: int, stdout_excerpt: string, stderr_excerpt: string} */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'constraint' => $this->constraint,
            'command' => $this->command,
            'exit_code' => $this->exitCode,
            'stdout_excerpt' => OutputExcerpt::bounded($this->stdout),
            'stderr_excerpt' => OutputExcerpt::bounded($this->stderr),
        ];
    }
}
