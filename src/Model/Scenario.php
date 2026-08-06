<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class Scenario
{
    private string $name;
    private UpgradeTargetSet $targets;
    private bool $withAllDependencies;
    private bool $minimalChanges;
    private bool $baselineValidation;
    private bool $targetFeasibility;

    public function __construct(
        string $name,
        UpgradeTargetSet $targets,
        bool $withAllDependencies = true,
        bool $minimalChanges = false,
        bool $baselineValidation = false,
        bool $targetFeasibility = true
    ) {
        $this->name = $name;
        $this->targets = $targets;
        $this->withAllDependencies = $withAllDependencies;
        $this->minimalChanges = $minimalChanges;
        $this->baselineValidation = $baselineValidation;
        $this->targetFeasibility = $targetFeasibility;
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

    public function isBaselineValidation(): bool
    {
        return $this->baselineValidation;
    }

    public function determinesTargetFeasibility(): bool
    {
        return !$this->baselineValidation && $this->targetFeasibility;
    }

    public function isPartialTargetProbe(): bool
    {
        return !$this->baselineValidation && !$this->targetFeasibility;
    }
}
