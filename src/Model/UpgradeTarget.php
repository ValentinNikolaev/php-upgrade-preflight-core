<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class UpgradeTarget
{
    private string $package;
    private string $constraint;

    public function __construct(string $package, string $constraint)
    {
        $this->package = $package;
        $this->constraint = $constraint;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function constraint(): string
    {
        return $this->constraint;
    }

    public static function fromString(string $target): self
    {
        $position = strpos($target, ':');

        if ($position === false || $position === 0 || $position === strlen($target) - 1) {
            throw new \InvalidArgumentException(sprintf('Target "%s" must use package:constraint syntax.', $target));
        }

        return new self(substr($target, 0, $position), substr($target, $position + 1));
    }

    /** @return array{package: string, constraint: string} */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'constraint' => $this->constraint,
        ];
    }
}
