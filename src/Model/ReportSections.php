<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ReportSections
{
    /** @var list<RootConstraintChange> */
    private array $rootConstraintChanges;
    /** @var list<PlanStage> */
    private array $planStages;
    /** @var list<TestGuidance> */
    private array $tests;
    /** @var list<string> */
    private array $uncertainties;

    /**
     * @param list<RootConstraintChange> $rootConstraintChanges
     * @param list<PlanStage> $planStages
     * @param list<TestGuidance> $tests
     * @param list<string> $uncertainties
     */
    public function __construct(array $rootConstraintChanges, array $planStages, array $tests, array $uncertainties)
    {
        $this->rootConstraintChanges = array_values($rootConstraintChanges);
        $this->planStages = array_values($planStages);
        $this->tests = array_values($tests);
        $this->uncertainties = array_values(array_unique($uncertainties));
    }

    /** @return list<RootConstraintChange> */
    public function rootConstraintChanges(): array
    {
        return $this->rootConstraintChanges;
    }

    /** @return list<PlanStage> */
    public function planStages(): array
    {
        return $this->planStages;
    }

    /** @return list<TestGuidance> */
    public function tests(): array
    {
        return $this->tests;
    }

    /** @return list<string> */
    public function uncertainties(): array
    {
        return $this->uncertainties;
    }
}
