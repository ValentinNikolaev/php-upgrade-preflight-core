<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class Scenario
{
    public string $name;
    /** @var list<UpgradeTarget> */
    public array $targets;
    public bool $withAllDependencies;
    public bool $minimalChanges;

    /** @param list<UpgradeTarget> $targets */
    public function __construct(string $name, array $targets, bool $withAllDependencies = true, bool $minimalChanges = false)
    {
        $this->name = $name;
        $this->targets = array_values($targets);
        $this->withAllDependencies = $withAllDependencies;
        $this->minimalChanges = $minimalChanges;
    }
}
