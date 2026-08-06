<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class SourceUsage
{
    public string $file;
    public string $symbol;
    public string $usageType;
    public ?int $line;
    /** @var list<string> */
    public array $evidence;

    /** @param list<string> $evidence */
    public function __construct(string $file, string $symbol, string $usageType, array $evidence, ?int $line = null)
    {
        $this->file = $file;
        $this->symbol = $symbol;
        $this->usageType = $usageType;
        $this->line = $line;
        $this->evidence = array_values($evidence);
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'symbol' => $this->symbol,
            'usage_type' => $this->usageType,
            'line' => $this->line,
            'evidence' => $this->evidence,
        ];
    }
}
