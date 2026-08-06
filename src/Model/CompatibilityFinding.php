<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class CompatibilityFinding
{
    private string $framework;
    private string $severity;
    private string $summary;
    /** @var list<string> */
    private array $evidence;

    /** @param list<string> $evidence */
    public function __construct(string $framework, string $severity, string $summary, array $evidence)
    {
        $this->framework = $framework;
        $this->severity = $severity;
        $this->summary = $summary;
        $this->evidence = array_values($evidence);
    }

    public function framework(): string
    {
        return $this->framework;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /** @return array{framework: string, severity: string, summary: string, evidence: list<string>} */
    public function toArray(): array
    {
        return [
            'framework' => $this->framework,
            'severity' => $this->severity,
            'summary' => $this->summary,
            'evidence' => $this->evidence,
        ];
    }
}
