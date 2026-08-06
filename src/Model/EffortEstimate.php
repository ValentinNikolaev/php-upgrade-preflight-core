<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class EffortEstimate
{
    /** @var array{0:int,1:int} */
    private array $rangeHours;
    private string $confidence;
    /** @var array<string, array{0:int,1:int}> */
    private array $components;
    /** @var list<string> */
    private array $assumptions;

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

    /** @return array{0:int,1:int} */
    public function rangeHours(): array
    {
        return $this->rangeHours;
    }

    public function confidence(): string
    {
        return $this->confidence;
    }

    /** @return array<string, array{0:int,1:int}> */
    public function components(): array
    {
        return $this->components;
    }

    /** @return list<string> */
    public function assumptions(): array
    {
        return $this->assumptions;
    }

    /** @return array{range_hours: array{0:int,1:int}, confidence: string, components: array<string, array{0:int,1:int}>|\stdClass, assumptions: list<string>} */
    public function toArray(): array
    {
        return [
            'range_hours' => $this->rangeHours,
            'confidence' => $this->confidence,
            'components' => $this->components === [] ? new \stdClass() : $this->components,
            'assumptions' => $this->assumptions,
        ];
    }
}
