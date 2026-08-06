<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class Blocker
{
    private string $type;
    private string $subject;
    private ?string $requestedConstraint;
    private ?string $blocker;
    private ?string $lockedVersion;
    private ?string $conflict;
    /** @var list<string> */
    private array $dependencyPath;
    /** @var list<string> */
    private array $options;
    private string $summary;
    private string $confidence;
    /** @var list<string> */
    private array $evidence;

    /**
     * @param list<string> $evidence
     * @param list<string> $dependencyPath
     * @param list<string> $options
     */
    public function __construct(
        string $type,
        string $subject,
        string $summary,
        string $confidence,
        array $evidence,
        ?string $requestedConstraint = null,
        ?string $blocker = null,
        ?string $lockedVersion = null,
        ?string $conflict = null,
        array $dependencyPath = [],
        array $options = []
    ) {
        $this->type = $type;
        $this->subject = $subject;
        $this->requestedConstraint = $requestedConstraint;
        $this->blocker = $blocker;
        $this->lockedVersion = $lockedVersion;
        $this->conflict = $conflict;
        $this->dependencyPath = array_values(array_unique($dependencyPath));
        $this->options = array_values(array_unique($options));
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

    public function requestedConstraint(): ?string
    {
        return $this->requestedConstraint;
    }

    public function blocker(): ?string
    {
        return $this->blocker;
    }

    public function lockedVersion(): ?string
    {
        return $this->lockedVersion;
    }

    public function conflict(): ?string
    {
        return $this->conflict;
    }

    /** @return list<string> */
    public function dependencyPath(): array
    {
        return $this->dependencyPath;
    }

    /** @return list<string> */
    public function options(): array
    {
        return $this->options;
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

    /** @return array{type: string, subject: string, requested_constraint: ?string, blocker: ?string, locked_version: ?string, conflict: ?string, dependency_path: list<string>, options: list<string>, summary: string, confidence: string, evidence: list<string>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'subject' => $this->subject,
            'requested_constraint' => $this->requestedConstraint,
            'blocker' => $this->blocker,
            'locked_version' => $this->lockedVersion,
            'conflict' => $this->conflict,
            'dependency_path' => $this->dependencyPath,
            'options' => $this->options,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence,
        ];
    }
}
