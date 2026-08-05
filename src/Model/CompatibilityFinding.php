<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class CompatibilityFinding
{
    public string $framework;
    public string $severity;
    public string $summary;
    /** @var list<string> */
    public array $evidence;

    /** @param list<string> $evidence */
    public function __construct(string $framework, string $severity, string $summary, array $evidence)
    {
        $this->framework = $framework;
        $this->severity = $severity;
        $this->summary = $summary;
        $this->evidence = array_values($evidence);
    }

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
