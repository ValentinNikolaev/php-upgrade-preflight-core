<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class RiskSummary
{
    private string $level;
    /** @var list<string> */
    private array $drivers;

    /** @param list<string> $drivers */
    public function __construct(string $level, array $drivers)
    {
        $this->level = $level;
        $this->drivers = array_values($drivers);
    }

    public function level(): string
    {
        return $this->level;
    }

    /** @return list<string> */
    public function drivers(): array
    {
        return $this->drivers;
    }

    /** @return array{level: string, drivers: list<string>} */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'drivers' => $this->drivers,
        ];
    }
}
