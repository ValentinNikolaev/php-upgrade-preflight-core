<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class Scenario
{
    public string $name;
    public UpgradeTargetSet $targets;
    public bool $withAllDependencies;
    public bool $minimalChanges;

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
}
