<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class Blocker
{
    public string $type;
    public string $subject;
    public string $summary;
    public string $confidence;
    /** @var list<string> */
    public array $evidence;

    /** @param list<string> $evidence */
    public function __construct(string $type, string $subject, string $summary, string $confidence, array $evidence)
    {
        $this->type = $type;
        $this->subject = $subject;
        $this->summary = $summary;
        $this->confidence = $confidence;
        $this->evidence = array_values($evidence);
    }

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
