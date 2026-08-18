<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

/**
 * A single `<package> <version> <operation> <dependency> <constraint>` relation
 * parsed out of Composer solver or `composer prohibits` output.
 */
final class SolverRelation
{
    public const REQUIRES = 'requires';
    public const CONFLICTS_WITH = 'conflicts with';
    public const REPLACES = 'replaces';
    public const PROVIDES = 'provides';

    private string $package;
    private ?string $version;
    private string $operation;
    private string $dependency;
    private ?string $constraint;

    public function __construct(
        string $package,
        ?string $version,
        string $operation,
        string $dependency,
        ?string $constraint
    ) {
        $this->package = $package;
        $this->version = $version;
        $this->operation = $operation;
        $this->dependency = $dependency;
        $this->constraint = $constraint;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function dependency(): string
    {
        return $this->dependency;
    }

    public function constraint(): ?string
    {
        return $this->constraint;
    }

    /** True when the relation expresses a replace/provide/conflict rule rather than a requirement. */
    public function isIncompatibilityRule(): bool
    {
        return in_array($this->operation, [self::REPLACES, self::PROVIDES, self::CONFLICTS_WITH], true);
    }
}
