<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Model\ScenarioResult;

/**
 * The classified result of one Composer scenario execution.
 *
 * The pair is always carried together because {@see ScenarioResult} rejects any
 * outcome whose failure type does not match the outcome vocabulary.
 */
final class ScenarioOutcome
{
    private ?string $failureType;
    private string $outcome;

    public function __construct(?string $failureType, string $outcome)
    {
        if (!in_array($outcome, ScenarioResult::supportedOutcomes(), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported scenario outcome "%s".', $outcome));
        }

        $this->failureType = $failureType;
        $this->outcome = $outcome;
    }

    public static function success(): self
    {
        return new self(null, ScenarioResult::OUTCOME_SUCCESS);
    }

    public static function operational(string $outcome): self
    {
        return new self(ScenarioResult::FAILURE_OPERATIONAL, $outcome);
    }

    public static function solver(): self
    {
        return new self(ScenarioResult::FAILURE_SOLVER, ScenarioResult::OUTCOME_SOLVER_FAILURE);
    }

    public static function validation(): self
    {
        return new self(ScenarioResult::FAILURE_VALIDATION, ScenarioResult::OUTCOME_VALIDATION_FAILURE);
    }

    public function failureType(): ?string
    {
        return $this->failureType;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function isSolverFailure(): bool
    {
        return $this->failureType === ScenarioResult::FAILURE_SOLVER;
    }
}
