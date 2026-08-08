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
    /** @var list<array{from_major: int, to_major: int}> */
    private array $appliesToHops;

    /** @param list<string> $evidence @param list<array{from_major: int, to_major: int}> $appliesToHops */
    public function __construct(
        string $framework,
        string $severity,
        string $summary,
        array $evidence,
        array $appliesToHops = []
    ) {
        $normalizedHops = [];
        foreach ($appliesToHops as $hop) {
            if (!is_array($hop)
                || !isset($hop['from_major'], $hop['to_major'])
                || !is_int($hop['from_major'])
                || !is_int($hop['to_major'])
                || $hop['from_major'] < 0
                || $hop['to_major'] <= $hop['from_major']) {
                throw new \InvalidArgumentException('Framework finding hop references must contain ascending non-negative integer majors.');
            }
            $normalizedHops[serialize($hop)] = [
                'from_major' => $hop['from_major'],
                'to_major' => $hop['to_major'],
            ];
        }

        $this->framework = $framework;
        $this->severity = $severity;
        $this->summary = $summary;
        $this->evidence = array_values($evidence);
        $this->appliesToHops = array_values($normalizedHops);
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

    /** @return list<array{from_major: int, to_major: int}> */
    public function appliesToHops(): array
    {
        return $this->appliesToHops;
    }

    /** @param list<array{from_major: int, to_major: int}> $appliesToHops */
    public function withAppliesToHops(array $appliesToHops): self
    {
        return new self(
            $this->framework,
            $this->severity,
            $this->summary,
            $this->evidence,
            $appliesToHops
        );
    }

    /** @return array{framework: string, severity: string, summary: string, applies_to_hops: list<array{from_major: int, to_major: int}>, evidence: list<string>} */
    public function toArray(): array
    {
        return [
            'framework' => $this->framework,
            'severity' => $this->severity,
            'summary' => $this->summary,
            'applies_to_hops' => $this->appliesToHops,
            'evidence' => $this->evidence,
        ];
    }
}
