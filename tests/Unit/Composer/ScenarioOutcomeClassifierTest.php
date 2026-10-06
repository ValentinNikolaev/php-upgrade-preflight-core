<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\InvalidJsonException;
use PhpUpgradePreflight\Core\Composer\ScenarioOutcome;
use PhpUpgradePreflight\Core\Composer\ScenarioOutcomeClassifier;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceCleanupException;
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ScenarioOutcomeClassifierTest extends TestCase
{
    /**
     * @dataProvider processResultProvider
     * @param array{exit_code: int, stdout: string, stderr: string} $process
     */
    public function testProcessResultsAreClassifiedIntoTheSupportedVocabulary(
        array $process,
        bool $hasCandidateLock,
        bool $baselineValidation,
        bool $restricted,
        ?string $expectedFailureType,
        string $expectedOutcome
    ): void {
        $classification = (new ScenarioOutcomeClassifier())->classifyProcessResult(
            $process,
            $hasCandidateLock,
            $this->scenario($baselineValidation),
            $restricted
                ? ComposerExecutionConfiguration::restricted()
                : ComposerExecutionConfiguration::compatible()
        );

        self::assertSame($expectedFailureType, $classification->failureType());
        self::assertSame($expectedOutcome, $classification->outcome());
    }

    /** @return array<string, array{array{exit_code: int, stdout: string, stderr: string}, bool, bool, bool, ?string, string}> */
    public function processResultProvider(): array
    {
        return [
            'resolved candidate' => [
                ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''],
                true,
                false,
                false,
                null,
                ScenarioResult::OUTCOME_SUCCESS,
            ],
            'resolved without a lockfile' => [
                ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''],
                false,
                false,
                false,
                ScenarioResult::FAILURE_OPERATIONAL,
                ScenarioResult::OUTCOME_LOCKFILE_MISSING,
            ],
            'missing Composer binary' => [
                ['exit_code' => 127, 'stdout' => '', 'stderr' => ''],
                false,
                false,
                false,
                ScenarioResult::FAILURE_OPERATIONAL,
                ScenarioResult::OUTCOME_COMPOSER_MISSING,
            ],
            'restricted metadata gap' => [
                ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Network disabled, request canceled.'],
                false,
                false,
                true,
                ScenarioResult::FAILURE_OPERATIONAL,
                ScenarioResult::OUTCOME_REPOSITORY_METADATA_UNAVAILABLE,
            ],
            'compatible mode does not claim a metadata gap' => [
                ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Network disabled, request canceled.'],
                false,
                false,
                false,
                ScenarioResult::FAILURE_OPERATIONAL,
                ScenarioResult::OUTCOME_PROCESS_FAILURE,
            ],
            'baseline validation failure' => [
                ['exit_code' => 1, 'stdout' => '', 'stderr' => 'The lock file is not up to date.'],
                false,
                true,
                false,
                ScenarioResult::FAILURE_VALIDATION,
                ScenarioResult::OUTCOME_VALIDATION_FAILURE,
            ],
            'solver failure' => [
                [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => 'Your requirements could not be resolved to an installable set of packages.',
                ],
                false,
                false,
                false,
                ScenarioResult::FAILURE_SOLVER,
                ScenarioResult::OUTCOME_SOLVER_FAILURE,
            ],
            'unexplained nonzero exit' => [
                ['exit_code' => 3, 'stdout' => '', 'stderr' => 'Unexpected failure.'],
                false,
                false,
                false,
                ScenarioResult::FAILURE_OPERATIONAL,
                ScenarioResult::OUTCOME_PROCESS_FAILURE,
            ],
        ];
    }

    /** @dataProvider phaseProvider */
    public function testOnlyTheProcessPhaseMayPublishAComposerProcessFailure(
        string $phase,
        string $expectedOutcome
    ): void {
        $classification = (new ScenarioOutcomeClassifier())->classifyException(
            new \RuntimeException('Unable to create analyzer-owned restricted Composer state.'),
            $phase
        );

        self::assertSame(ScenarioResult::FAILURE_OPERATIONAL, $classification->failureType());
        self::assertSame($expectedOutcome, $classification->outcome());
    }

    /** @return array<string, array{string, string}> */
    public function phaseProvider(): array
    {
        return [
            'workspace' => [
                ScenarioOutcomeClassifier::PHASE_WORKSPACE,
                ScenarioResult::OUTCOME_WORKSPACE_FAILURE,
            ],
            'preparation' => [
                ScenarioOutcomeClassifier::PHASE_PREPARATION,
                ScenarioResult::OUTCOME_WORKSPACE_FAILURE,
            ],
            'lockfile' => [
                ScenarioOutcomeClassifier::PHASE_LOCKFILE,
                ScenarioResult::OUTCOME_WORKSPACE_FAILURE,
            ],
            'process' => [
                ScenarioOutcomeClassifier::PHASE_PROCESS,
                ScenarioResult::OUTCOME_PROCESS_FAILURE,
            ],
        ];
    }

    public function testFilesystemFailuresBeforeTheProcessPhaseAreNeverReportedAsMissingComposer(): void
    {
        $classifier = new ScenarioOutcomeClassifier();
        // The generic Windows phrase is also emitted by ordinary filesystem calls.
        $exception = new \RuntimeException('CreateDirectory(): The system cannot find the file specified');

        self::assertSame(
            ScenarioResult::OUTCOME_WORKSPACE_FAILURE,
            $classifier->classifyException($exception, ScenarioOutcomeClassifier::PHASE_PREPARATION)->outcome()
        );
        self::assertSame(
            ScenarioResult::OUTCOME_COMPOSER_MISSING,
            $classifier->classifyException($exception, ScenarioOutcomeClassifier::PHASE_PROCESS)->outcome()
        );
    }

    public function testTypedFailuresKeepTheirOwnOutcomeRegardlessOfPhase(): void
    {
        $classifier = new ScenarioOutcomeClassifier();

        self::assertSame(
            ScenarioResult::OUTCOME_CLEANUP_FAILURE,
            $classifier->classifyException(
                new WorkspaceCleanupException('/tmp/workspace', 'Cleanup failed.'),
                ScenarioOutcomeClassifier::PHASE_WORKSPACE
            )->outcome()
        );
        self::assertSame(
            ScenarioResult::OUTCOME_INVALID_JSON,
            $classifier->classifyException(
                new InvalidJsonException('composer.lock', 'Syntax error'),
                ScenarioOutcomeClassifier::PHASE_LOCKFILE
            )->outcome()
        );
        self::assertSame(
            ScenarioResult::OUTCOME_TIMEOUT,
            $classifier->classifyException($this->timeout(), ScenarioOutcomeClassifier::PHASE_PROCESS)->outcome()
        );
    }

    public function testDiagnosticsSeparateUnusableExecutionFromOrdinaryNonzeroExits(): void
    {
        $classifier = new ScenarioOutcomeClassifier();
        $compatible = ComposerExecutionConfiguration::compatible();

        self::assertSame(
            ScenarioResult::OUTCOME_SUCCESS,
            $classifier->classifyDiagnosticResult(
                ['exit_code' => 1, 'stdout' => 'fixture/blocker 1.0.0 requires fixture/dependency (^1.0)', 'stderr' => ''],
                $compatible
            ),
            'A nonzero prohibits exit is ordinary evidence, not an execution failure.'
        );
        self::assertSame(
            ScenarioResult::OUTCOME_COMPOSER_MISSING,
            $classifier->classifyDiagnosticResult(
                ['exit_code' => 127, 'stdout' => '', 'stderr' => ''],
                $compatible
            )
        );
        self::assertSame(
            ScenarioResult::OUTCOME_REPOSITORY_METADATA_UNAVAILABLE,
            $classifier->classifyDiagnosticResult(
                ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Offline mode: package information is not available.'],
                ComposerExecutionConfiguration::restricted()
            )
        );
        self::assertSame(
            ScenarioResult::OUTCOME_TIMEOUT,
            $classifier->classifyDiagnosticException($this->timeout())
        );
        self::assertSame(
            ScenarioResult::OUTCOME_PROCESS_FAILURE,
            $classifier->classifyDiagnosticException(new \RuntimeException('Diagnostic process failed to start.'))
        );
    }

    public function testTheOutcomeVocabularyIsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScenarioOutcome(ScenarioResult::FAILURE_OPERATIONAL, 'invented_outcome');
    }

    private function scenario(bool $baselineValidation): Scenario
    {
        $targets = new UpgradeTargetSet([new UpgradeTarget('fixture/dependency', '^2.0')]);

        return new Scenario('classifier', $targets, false, false, $baselineValidation);
    }

    private function timeout(): ProcessTimedOutException
    {
        $process = new Process(['composer', 'prohibits']);
        $process->setTimeout(60);

        return new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
    }
}
