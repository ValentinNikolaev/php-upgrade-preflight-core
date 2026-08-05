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

    public string $id;
    public string $class;
    public string $summary;
    public string $confidence;
    /** @var array<string, mixed> */
    public array $context;

    /** @param array<string, mixed> $context */
    public function __construct(string $id, string $class, string $summary, string $confidence = 'high', array $context = [])
    {
        $this->id = $id;
        $this->class = $class;
        $this->summary = $summary;
        $this->confidence = $confidence;
        $this->context = $context;
    }

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
