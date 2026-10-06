<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

/**
 * What a blocker is attributed to: the blocking package, the version it is
 * pinned at, and the constraint that conflicts.
 *
 * Groups the three optional attribution fields of {@see Blocker} so analyzers
 * pass one named value instead of a run of interchangeable nullable strings.
 */
final class BlockerAttribution
{
    private ?string $blockingPackage;
    private ?string $lockedVersion;
    private ?string $conflict;

    public function __construct(?string $blockingPackage, ?string $lockedVersion, ?string $conflict)
    {
        $this->blockingPackage = $blockingPackage;
        $this->lockedVersion = $lockedVersion;
        $this->conflict = $conflict;
    }

    /** The blocker names no package and no conflicting constraint. */
    public static function none(): self
    {
        return new self(null, null, null);
    }

    /** The blocker names a conflicting constraint that no single package owns. */
    public static function forConstraint(?string $conflict): self
    {
        return new self(null, null, $conflict);
    }

    public static function fromRelation(SolverRelation $relation): self
    {
        return new self($relation->package(), $relation->version(), $relation->constraint());
    }

    public function blockingPackage(): ?string
    {
        return $this->blockingPackage;
    }

    public function lockedVersion(): ?string
    {
        return $this->lockedVersion;
    }

    public function conflict(): ?string
    {
        return $this->conflict;
    }
}
