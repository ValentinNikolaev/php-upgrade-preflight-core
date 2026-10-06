<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class RootConstraintChange
{
    private string $package;
    private string $changeType;
    private ?string $fromConstraint;
    private ?string $toConstraint;
    private string $reason;
    /** @var list<string> */
    private array $evidence;

    /** @param list<string> $evidence */
    public function __construct(
        string $package,
        string $changeType,
        ?string $fromConstraint,
        ?string $toConstraint,
        string $reason,
        array $evidence
    ) {
        $this->package = $package;
        $this->changeType = $changeType;
        $this->fromConstraint = $fromConstraint;
        $this->toConstraint = $toConstraint;
        $this->reason = $reason;
        $this->evidence = array_values(array_unique($evidence));
    }

    public function package(): string
    {
        return $this->package;
    }

    public function changeType(): string
    {
        return $this->changeType;
    }

    public function fromConstraint(): ?string
    {
        return $this->fromConstraint;
    }

    public function toConstraint(): ?string
    {
        return $this->toConstraint;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /** @return array{package: string, change_type: string, from_constraint: ?string, to_constraint: ?string, reason: string, evidence: list<string>} */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'change_type' => $this->changeType,
            'from_constraint' => $this->fromConstraint,
            'to_constraint' => $this->toConstraint,
            'reason' => $this->reason,
            'evidence' => $this->evidence,
        ];
    }
}
