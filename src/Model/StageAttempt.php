<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class StageAttempt
{
    private int $number;
    private string $strategy;
    /** @var list<RootConstraintChange> */
    private array $rootConstraintChanges;
    private ScenarioResult $scenario;
    private ProjectStateFingerprint $inputState;
    private ?ProjectStateFingerprint $outputState;
    /** @var list<string> */
    private array $blockerIds;
    /** @var list<string> */
    private array $evidence;
    private bool $selected;

    /**
     * @param list<RootConstraintChange> $rootConstraintChanges
     * @param list<string> $blockerIds
     * @param list<string> $evidence
     */
    public function __construct(
        int $number,
        string $strategy,
        array $rootConstraintChanges,
        ScenarioResult $scenario,
        ProjectStateFingerprint $inputState,
        ?ProjectStateFingerprint $outputState,
        array $blockerIds,
        array $evidence,
        bool $selected = false
    ) {
        if ($number < 1) {
            throw new \InvalidArgumentException('Stage attempt numbers start at one.');
        }
        foreach ($rootConstraintChanges as $change) {
            if (!$change instanceof RootConstraintChange) {
                throw new \InvalidArgumentException('Stage root changes must be RootConstraintChange instances.');
            }
        }

        $this->number = $number;
        $this->strategy = $strategy;
        $this->rootConstraintChanges = array_values($rootConstraintChanges);
        $this->scenario = $scenario;
        $this->inputState = $inputState;
        $this->outputState = $outputState;
        $this->blockerIds = array_values(array_unique($blockerIds));
        $this->evidence = array_values(array_unique($evidence));
        $this->selected = $selected;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function scenario(): ScenarioResult
    {
        return $this->scenario;
    }

    /** @return list<string> */
    public function blockerIds(): array
    {
        return $this->blockerIds;
    }

    public function withSelected(): self
    {
        return new self(
            $this->number,
            $this->strategy,
            $this->rootConstraintChanges,
            $this->scenario,
            $this->inputState,
            $this->outputState,
            $this->blockerIds,
            $this->evidence,
            true
        );
    }

    /** @return list<string> */
    public function evidenceReferences(): array
    {
        $references = $this->evidence;
        foreach ($this->rootConstraintChanges as $change) {
            $references = array_merge($references, $change->evidence());
        }

        return array_values(array_unique($references));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'strategy' => $this->strategy,
            'root_constraint_changes' => array_map(
                static fn (RootConstraintChange $change): array => $change->toArray(),
                $this->rootConstraintChanges
            ),
            'scenario' => $this->scenario->toArray(),
            'input_state' => $this->inputState->toArray(),
            'output_state' => $this->outputState === null ? null : $this->outputState->toArray(),
            'blocker_ids' => $this->blockerIds,
            'evidence' => $this->evidence,
            'selected' => $this->selected,
        ];
    }
}
