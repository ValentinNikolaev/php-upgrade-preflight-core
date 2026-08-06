<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class PlanStage
{
    private string $name;
    private string $summary;
    /** @var list<string> */
    private array $actions;
    /** @var list<string> */
    private array $evidence;

    /** @param list<string> $actions @param list<string> $evidence */
    public function __construct(string $name, string $summary, array $actions, array $evidence)
    {
        $this->name = $name;
        $this->summary = $summary;
        $this->actions = array_values($actions);
        $this->evidence = array_values(array_unique($evidence));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /** @return list<string> */
    public function actions(): array
    {
        return $this->actions;
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /** @return array{name: string, summary: string, actions: list<string>, evidence: list<string>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'summary' => $this->summary,
            'actions' => $this->actions,
            'evidence' => $this->evidence,
        ];
    }
}
