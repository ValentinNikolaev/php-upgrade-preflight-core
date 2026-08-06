<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class Scenario
{
    private string $name;
    private UpgradeTargetSet $targets;
    private bool $withAllDependencies;
    private bool $minimalChanges;

    public function __construct(
        string $name,
        UpgradeTargetSet $targets,
        bool $withAllDependencies = true,
        bool $minimalChanges = false
    ) {
        $this->name = $name;
        $this->targets = $targets;
        $this->withAllDependencies = $withAllDependencies;
        $this->minimalChanges = $minimalChanges;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function targets(): UpgradeTargetSet
    {
        return $this->targets;
    }

    public function withAllDependencies(): bool
    {
        return $this->withAllDependencies;
    }

    public function minimalChanges(): bool
    {
        return $this->minimalChanges;
    }
}
