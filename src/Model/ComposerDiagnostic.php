<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

use PhpUpgradePreflight\Core\Support\OutputExcerpt;
use PhpUpgradePreflight\Core\Support\SensitiveOutputRedactor;

final class ComposerDiagnostic
{
    private string $package;
    private string $constraint;
    /** @var list<string> */
    private array $command;
    private int $exitCode;
    private string $stdout;
    private string $stderr;
    private string $outcome;

    /**
     * @param list<string> $command
     * @param ?string $outcome How the diagnostic process itself ended, using the
     *        {@see ScenarioResult::supportedOutcomes()} vocabulary. A nonzero
     *        exit status is ordinary evidence for `composer prohibits`, so the
     *        outcome, not the exit code, distinguishes a timeout or a missing
     *        Composer binary from a diagnostic that produced usable output.
     *        Defaults to `success` when the caller supplies none, because a non-zero
     *        `composer prohibits` exit is ordinary evidence rather than a failed probe.
     */
    public function __construct(
        string $package,
        string $constraint,
        array $command,
        int $exitCode,
        string $stdout,
        string $stderr,
        ?string $outcome = null
    ) {
        foreach ($command as $argument) {
            if (!is_string($argument)) {
                throw new \InvalidArgumentException('Composer diagnostic command arguments must be strings.');
            }
        }

        // A non-zero exit is the ORDINARY result of `composer prohibits` — it means the relation was
        // found, which is exactly the evidence a diagnostic exists to capture. Only unusable
        // execution (timeout, missing binary, unreadable repository metadata) downgrades the
        // outcome, and the runner passes that explicitly. Defaulting on exit status alone would
        // publish `process_failure` for evidence the classifier records as `success`.
        $outcome = $outcome ?? ScenarioResult::OUTCOME_SUCCESS;
        if (!in_array($outcome, ScenarioResult::supportedOutcomes(), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported Composer diagnostic outcome "%s".', $outcome));
        }

        $this->package = $package;
        $this->constraint = $constraint;
        $this->command = array_values($command);
        $this->exitCode = $exitCode;
        $this->stdout = SensitiveOutputRedactor::redact($stdout);
        $this->stderr = SensitiveOutputRedactor::redact($stderr);
        $this->outcome = $outcome;
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

    public function outcome(): string
    {
        return $this->outcome;
    }

    /** @return array{package: string, constraint: string, command: list<string>, exit_code: int, outcome: string, stdout_excerpt: string, stderr_excerpt: string} */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'constraint' => $this->constraint,
            'command' => $this->command,
            'exit_code' => $this->exitCode,
            'outcome' => $this->outcome,
            'stdout_excerpt' => OutputExcerpt::bounded($this->stdout),
            'stderr_excerpt' => OutputExcerpt::bounded($this->stderr),
        ];
    }
}
