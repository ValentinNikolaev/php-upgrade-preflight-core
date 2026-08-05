<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class EffortEstimate
{
    /** @var array{0:int,1:int} */
    public array $rangeHours;
    public string $confidence;
    /** @var array<string, array{0:int,1:int}> */
    public array $components;
    /** @var list<string> */
    public array $assumptions;

    /**
     * @param array{0:int,1:int} $rangeHours
     * @param array<string, array{0:int,1:int}> $components
     * @param list<string> $assumptions
     */
    public function __construct(array $rangeHours, string $confidence, array $components, array $assumptions)
    {
        $this->rangeHours = $rangeHours;
        $this->confidence = $confidence;
        $this->components = $components;
        $this->assumptions = array_values($assumptions);
    }

    public function toArray(): array
    {
        return [
            'range_hours' => $this->rangeHours,
            'confidence' => $this->confidence,
            'components' => $this->components,
            'assumptions' => $this->assumptions,
        ];
    }
}
