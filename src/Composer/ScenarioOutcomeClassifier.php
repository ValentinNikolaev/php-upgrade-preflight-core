<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Filesystem\WorkspaceCleanupException;
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Turns raw Composer execution evidence into the structured failure type and
 * outcome vocabulary that {@see ScenarioResult} enforces.
 *
 * The phase constants are the closed vocabulary of the scenario state machine.
 * They are declared here because the exception classification is the only reader
 * of the phase a scenario reached.
 */
final class ScenarioOutcomeClassifier
{
    public const PHASE_WORKSPACE = 'workspace';
    public const PHASE_PREPARATION = 'preparation';
    public const PHASE_PROCESS = 'process';
    public const PHASE_LOCKFILE = 'lockfile';

    /**
     * Classifies a Composer process that ran to completion.
     *
     * @param array{exit_code: int, stdout: string, stderr: string} $process
     */
    public function classifyProcessResult(
        array $process,
        bool $hasCandidateLock,
        Scenario $scenario,
        ComposerExecutionConfiguration $execution
    ): ScenarioOutcome {
        if ($process['exit_code'] === 0) {
            return $hasCandidateLock
                ? ScenarioOutcome::success()
                : ScenarioOutcome::operational(ScenarioResult::OUTCOME_LOCKFILE_MISSING);
        }

        return match (true) {
            $this->indicatesMissingComposer($process['exit_code'], $process['stdout'], $process['stderr'])
                => ScenarioOutcome::operational(ScenarioResult::OUTCOME_COMPOSER_MISSING),
            $execution->isRestricted()
                && $this->indicatesUnavailableRepositoryMetadata($process['stdout'], $process['stderr'])
                => ScenarioOutcome::operational(ScenarioResult::OUTCOME_REPOSITORY_METADATA_UNAVAILABLE),
            $scenario->isBaselineValidation() => ScenarioOutcome::validation(),
            $this->isSolverFailure($process['stdout'], $process['stderr']) => ScenarioOutcome::solver(),
            default => ScenarioOutcome::operational(ScenarioResult::OUTCOME_PROCESS_FAILURE),
        };
    }

    /**
     * Classifies a scenario that aborted with an exception.
     *
     * Only the process phase may report a Composer process failure: any earlier
     * phase failed before a Composer process was started, so a workspace,
     * seeding, manifest, or restricted-state failure must never be published as
     * a Composer failure.
     *
     * @param self::PHASE_* $phase
     */
    public function classifyException(\Throwable $exception, string $phase): ScenarioOutcome
    {
        return ScenarioOutcome::operational(match (true) {
            $exception instanceof WorkspaceCleanupException => ScenarioResult::OUTCOME_CLEANUP_FAILURE,
            $exception instanceof ProcessTimedOutException => ScenarioResult::OUTCOME_TIMEOUT,
            $exception instanceof InvalidJsonException => ScenarioResult::OUTCOME_INVALID_JSON,
            $phase !== self::PHASE_PROCESS => ScenarioResult::OUTCOME_WORKSPACE_FAILURE,
            $this->indicatesMissingComposer(1, '', $exception->getMessage())
                => ScenarioResult::OUTCOME_COMPOSER_MISSING,
            default => ScenarioResult::OUTCOME_PROCESS_FAILURE,
        });
    }

    /**
     * Classifies a `composer prohibits` diagnostic that ran to completion.
     *
     * A nonzero exit status is normal evidence for this command, so only an
     * unusable execution downgrades the outcome.
     *
     * @param array{exit_code: int, stdout: string, stderr: string} $process
     */
    public function classifyDiagnosticResult(
        array $process,
        ComposerExecutionConfiguration $execution
    ): string {
        return match (true) {
            $this->indicatesMissingComposer($process['exit_code'], $process['stdout'], $process['stderr'])
                => ScenarioResult::OUTCOME_COMPOSER_MISSING,
            $execution->isRestricted()
                && $this->indicatesUnavailableRepositoryMetadata($process['stdout'], $process['stderr'])
                => ScenarioResult::OUTCOME_REPOSITORY_METADATA_UNAVAILABLE,
            default => ScenarioResult::OUTCOME_SUCCESS,
        };
    }

    public function classifyDiagnosticException(\Throwable $exception): string
    {
        return $this->classifyException($exception, self::PHASE_PROCESS)->outcome();
    }

    private function isSolverFailure(string $stdout, string $stderr): bool
    {
        $output = $stdout . "\n" . $stderr;

        return stripos($output, 'Your requirements could not be resolved to an installable set of packages') !== false
            || preg_match('/(?:^|\n)\s*- Root composer\.json requires /i', $output) === 1;
    }

    private function indicatesMissingComposer(int $exitCode, string $stdout, string $stderr): bool
    {
        if (in_array($exitCode, [127, 9009], true)) {
            return true;
        }

        $output = $stdout . "\n" . $stderr;

        return preg_match('/(?:composer(?:\.bat|\.phar)?(?: executable)? (?:was |is )?(?:unavailable|missing|not found)|composer:\s*(?:command\s+)?not found|[\'\"]composer[\'\"] is not recognized|could not open input file:\s*composer|createprocess failed[^\n]*error=2|the system cannot find the file specified)/i', $output) === 1;
    }

    private function indicatesUnavailableRepositoryMetadata(string $stdout, string $stderr): bool
    {
        $output = $stdout . "\n" . $stderr;

        return preg_match(
            '/(?:network (?:is )?disabled|request canceled|offline mode|could not (?:download|load).*cache|metadata.*(?:not available|unavailable)|package information.*not available)/i',
            $output
        ) === 1;
    }
}
