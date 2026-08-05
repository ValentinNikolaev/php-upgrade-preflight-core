<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class RiskSummary
{
    public string $level;
    /** @var list<string> */
    public array $drivers;

    /** @param list<string> $drivers */
    public function __construct(string $level, array $drivers)
    {
        $this->level = $level;
        $this->drivers = array_values($drivers);
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'drivers' => $this->drivers,
        ];
    }
}
