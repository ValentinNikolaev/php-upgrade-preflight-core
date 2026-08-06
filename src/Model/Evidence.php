<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class Evidence
{
    public const E1_SOLVER = 'E1';
    public const E2_PACKAGE_METADATA = 'E2';
    public const E3_PROJECT_SOURCE = 'E3';
    public const E4_MAINTAINER_DOCUMENTATION = 'E4';
    public const E5_HEURISTIC = 'E5';

    private string $id;
    private string $class;
    private string $summary;
    private string $confidence;
    /** @var array<string, mixed> */
    private array $context;

    /** @param array<string, mixed> $context */
    public function __construct(string $id, string $class, string $summary, string $confidence = 'high', array $context = [])
    {
        $this->id = $id;
        $this->class = $class;
        $this->summary = $summary;
        $this->confidence = $confidence;
        $this->context = $context;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function evidenceClass(): string
    {
        return $this->class;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function confidence(): string
    {
        return $this->confidence;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /** @return array{id: string, class: string, summary: string, confidence: string, context: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'class' => $this->class,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'context' => $this->context,
        ];
    }
}
