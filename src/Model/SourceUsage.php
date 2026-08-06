<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class SourceUsage
{
    private string $file;
    private string $symbol;
    private string $usageType;
    private ?int $line;
    /** @var list<string> */
    private array $evidence;

    /** @param list<string> $evidence */
    public function __construct(string $file, string $symbol, string $usageType, array $evidence, ?int $line = null)
    {
        $this->file = $file;
        $this->symbol = $symbol;
        $this->usageType = $usageType;
        $this->line = $line;
        $this->evidence = array_values($evidence);
    }

    public function file(): string
    {
        return $this->file;
    }

    public function symbol(): string
    {
        return $this->symbol;
    }

    public function usageType(): string
    {
        return $this->usageType;
    }

    public function line(): ?int
    {
        return $this->line;
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /** @return array{file: string, symbol: string, usage_type: string, line: ?int, evidence: list<string>} */
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
