<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class SourceImpactFinding
{
    private ?string $affectedPackage;
    private string $ownership;
    private string $relevance;
    private string $reason;
    private string $severity;
    /** @var list<SourceUsage> */
    private array $occurrences;
    /** @var list<string> */
    private array $evidence;

    /** @param list<SourceUsage> $occurrences @param list<string> $evidence */
    public function __construct(
        ?string $affectedPackage,
        string $ownership,
        string $relevance,
        string $reason,
        string $severity,
        array $occurrences,
        array $evidence
    ) {
        if (!in_array($ownership, ['exact', 'ambiguous', 'unknown'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported source ownership "%s".', $ownership));
        }
        if (!in_array($relevance, ['package_change', 'framework_rule', 'package_change_and_framework_rule'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported source relevance "%s".', $relevance));
        }
        if (!in_array($severity, ['low', 'medium', 'high'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported source-impact severity "%s".', $severity));
        }
        if ($occurrences === []) {
            throw new \InvalidArgumentException('A source-impact finding must contain at least one occurrence.');
        }
        if ($evidence === []) {
            throw new \InvalidArgumentException('A source-impact finding must reference evidence.');
        }

        foreach ($occurrences as $occurrence) {
            if (!$occurrence instanceof SourceUsage) {
                throw new \InvalidArgumentException('Source-impact occurrences must be SourceUsage instances.');
            }
        }

        $this->affectedPackage = $affectedPackage;
        $this->ownership = $ownership;
        $this->relevance = $relevance;
        $this->reason = $reason;
        $this->severity = $severity;
        $this->occurrences = array_values($occurrences);
        $this->evidence = array_values(array_unique($evidence));
    }

    public function affectedPackage(): ?string
    {
        return $this->affectedPackage;
    }

    public function ownership(): string
    {
        return $this->ownership;
    }

    public function relevance(): string
    {
        return $this->relevance;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    /** @return list<SourceUsage> */
    public function occurrences(): array
    {
        return $this->occurrences;
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /**
     * @return array{
     *   affected_package: ?string,
     *   ownership: string,
     *   relevance: string,
     *   reason: string,
     *   severity: string,
     *   occurrences: list<array{file: string, symbol: string, usage_type: string, line: ?int, evidence: list<string>}>,
     *   evidence: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'affected_package' => $this->affectedPackage,
            'ownership' => $this->ownership,
            'relevance' => $this->relevance,
            'reason' => $this->reason,
            'severity' => $this->severity,
            'occurrences' => array_map(
                static fn (SourceUsage $occurrence): array => $occurrence->toArray(),
                $this->occurrences
            ),
            'evidence' => $this->evidence,
        ];
    }
}
