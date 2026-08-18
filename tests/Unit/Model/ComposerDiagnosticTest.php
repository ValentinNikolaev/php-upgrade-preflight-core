<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PHPUnit\Framework\TestCase;

final class ComposerDiagnosticTest extends TestCase
{
    public function testTheOutcomeIsPublishedBesideTheExitCode(): void
    {
        $diagnostic = new ComposerDiagnostic(
            'laravel/framework',
            '^10.0',
            ['composer', 'prohibits', 'laravel/framework', '^10.0'],
            1,
            '',
            'The process exceeded the timeout of 60 seconds.',
            ScenarioResult::OUTCOME_TIMEOUT
        );

        self::assertSame(ScenarioResult::OUTCOME_TIMEOUT, $diagnostic->outcome());
        $canonical = $diagnostic->toArray();
        self::assertSame(ScenarioResult::OUTCOME_TIMEOUT, $canonical['outcome']);
        self::assertSame(1, $canonical['exit_code']);
        self::assertSame([
            'package',
            'constraint',
            'command',
            'exit_code',
            'outcome',
            'stdout_excerpt',
            'stderr_excerpt',
        ], array_keys($canonical));
    }

    /**
     * A non-zero `composer prohibits` exit means the prohibited relation was found, which is the
     * evidence the probe exists to capture — not a failed probe. Inferring the outcome from exit
     * status alone would publish `process_failure` for the same run the runner records as
     * `success`, so an omitted outcome defaults to `success` and only the runner downgrades it.
     *
     * @dataProvider ordinaryExitProvider
     */
    public function testAnOmittedOutcomeDefaultsToSuccessRegardlessOfExitStatus(int $exitCode): void
    {
        $diagnostic = new ComposerDiagnostic('fixture/dependency', '^2.0', ['composer', 'prohibits'], $exitCode, '', '');

        self::assertSame(ScenarioResult::OUTCOME_SUCCESS, $diagnostic->outcome());
    }

    /** @return array<string, array{int}> */
    public function ordinaryExitProvider(): array
    {
        return [
            'clean exit' => [0],
            'relation found' => [2],
        ];
    }

    public function testAnUnusableProbeIsRecordedByTheCallerRatherThanInferred(): void
    {
        $timedOut = new ComposerDiagnostic(
            'fixture/dependency',
            '^2.0',
            ['composer', 'prohibits'],
            1,
            '',
            '',
            ScenarioResult::OUTCOME_TIMEOUT
        );

        self::assertSame(ScenarioResult::OUTCOME_TIMEOUT, $timedOut->outcome());
        self::assertSame(ScenarioResult::OUTCOME_TIMEOUT, $timedOut->toArray()['outcome']);
    }

    public function testTheOutcomeVocabularyMatchesScenarioResults(): void
    {
        foreach (ScenarioResult::supportedOutcomes() as $outcome) {
            $diagnostic = new ComposerDiagnostic(
                'fixture/dependency',
                '^2.0',
                ['composer', 'prohibits'],
                1,
                '',
                '',
                $outcome
            );

            self::assertSame($outcome, $diagnostic->outcome());
        }
    }

    public function testAnUnknownOutcomeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Composer diagnostic outcome "invented_outcome".');

        new ComposerDiagnostic(
            'fixture/dependency',
            '^2.0',
            ['composer', 'prohibits'],
            1,
            '',
            '',
            'invented_outcome'
        );
    }
}
