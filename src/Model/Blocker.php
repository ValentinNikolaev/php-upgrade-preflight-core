<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class Blocker
{
    private string $type;
    private string $subject;
    private string $summary;
    private string $confidence;
    /** @var list<string> */
    private array $evidence;

    /** @param list<string> $evidence */
    public function __construct(string $type, string $subject, string $summary, string $confidence, array $evidence)
    {
        $this->type = $type;
        $this->subject = $subject;
        $this->summary = $summary;
        $this->confidence = $confidence;
        $this->evidence = array_values($evidence);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function confidence(): string
    {
        return $this->confidence;
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /** @return array{type: string, subject: string, summary: string, confidence: string, evidence: list<string>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'subject' => $this->subject,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence,
        ];
    }
}
