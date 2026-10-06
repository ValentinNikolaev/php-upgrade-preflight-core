<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

use Composer\Semver\VersionParser;

final class UpgradeTarget
{
    private const COMPOSER_PACKAGE_PATTERN = '[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*';
    private const PLATFORM_PACKAGE_PATTERN = '(?:php(?:-64bit|-ipv6)?|ext-[a-z0-9_.-]+|lib-[a-z0-9_.-]+|composer(?:-plugin-api|-runtime-api)?)';

    private string $package;
    private string $constraint;

    /**
     * Normalize and validate here so every consumer receives a canonical, resolvable target and
     * no collection or adapter has to repeat the same normalization.
     */
    public function __construct(string $package, string $constraint)
    {
        $package = strtolower(trim($package));
        $constraint = trim($constraint);

        self::assertResolvablePackage($package);
        self::assertResolvableConstraint($package, $constraint);

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

    private static function assertResolvablePackage(string $package): void
    {
        $pattern = '~^(?:' . self::COMPOSER_PACKAGE_PATTERN . '|' . self::PLATFORM_PACKAGE_PATTERN . ')$~D';

        if ($package === '' || !preg_match($pattern, $package)) {
            throw new \InvalidArgumentException(sprintf('Invalid Composer target package "%s".', $package));
        }
    }

    private static function assertResolvableConstraint(string $package, string $constraint): void
    {
        if ($constraint === '') {
            throw new \InvalidArgumentException(sprintf('Target "%s" must have a non-empty constraint.', $package));
        }

        try {
            (new VersionParser())->parseConstraints($constraint);
        } catch (\UnexpectedValueException $exception) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid constraint "%s" for target "%s".',
                $constraint,
                $package
            ), 0, $exception);
        }
    }
}
