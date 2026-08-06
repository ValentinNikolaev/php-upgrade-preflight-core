<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

use Composer\Semver\VersionParser;

final class UpgradeTargetSet implements \Countable, \IteratorAggregate
{
    /** @var array<string, UpgradeTarget> */
    private array $targets;
    private ?string $targetPhp;

    /**
     * @param list<UpgradeTarget> $targets
     */
    public function __construct(array $targets, ?string $targetPhp = null)
    {
        $normalized = [];

        foreach ($targets as $index => $target) {
            if (!$target instanceof UpgradeTarget) {
                throw new \InvalidArgumentException(sprintf('Upgrade target at index %d must be an UpgradeTarget.', $index));
            }

            $package = strtolower(trim($target->package()));
            $constraint = trim($target->constraint());

            $this->validatePackage($package);

            if ($package === 'php') {
                $targetPhp = $this->mergePhpTarget($targetPhp, $constraint);
                continue;
            }

            $this->validateConstraint($package, $constraint);

            if (isset($normalized[$package])) {
                if ($normalized[$package]->constraint() !== $constraint) {
                    throw new \InvalidArgumentException(sprintf(
                        'Conflicting constraints for target "%s": "%s" and "%s".',
                        $package,
                        $normalized[$package]->constraint(),
                        $constraint
                    ));
                }

                continue;
            }

            $normalized[$package] = new UpgradeTarget($package, $constraint);
        }

        if ($targetPhp !== null) {
            $targetPhp = $this->normalizePhpVersion($targetPhp);
        }

        if ($normalized === [] && $targetPhp === null) {
            throw new \InvalidArgumentException('At least one upgrade target is required.');
        }

        ksort($normalized, SORT_STRING);

        $this->targets = $normalized;
        $this->targetPhp = $targetPhp;
    }

    /** @return list<UpgradeTarget> */
    public function packageTargets(): array
    {
        return array_values($this->copyTargets());
    }

    public function targetPhp(): ?string
    {
        return $this->targetPhp;
    }

    /** @return list<UpgradeTarget> */
    public function all(): array
    {
        $targets = $this->copyTargets();

        if ($this->targetPhp !== null) {
            $targets['php'] = new UpgradeTarget('php', $this->targetPhp);
            ksort($targets, SORT_STRING);
        }

        return array_values($targets);
    }

    /** @return list<array{package: string, constraint: string}> */
    public function toArray(): array
    {
        return array_map(static fn (UpgradeTarget $target): array => $target->toArray(), $this->all());
    }

    public function count(): int
    {
        return count($this->targets) + ($this->targetPhp === null ? 0 : 1);
    }

    /** @return \Traversable<int, UpgradeTarget> */
    public function getIterator(): \Traversable
    {
        yield from $this->all();
    }

    private function mergePhpTarget(?string $current, string $candidate): string
    {
        $normalizedCandidate = $this->normalizePhpVersion($candidate);

        if ($current === null) {
            return $normalizedCandidate;
        }

        $normalizedCurrent = $this->normalizePhpVersion($current);
        if ($normalizedCurrent !== $normalizedCandidate) {
            throw new \InvalidArgumentException(sprintf(
                'Conflicting PHP targets: "%s" and "%s".',
                $normalizedCurrent,
                $normalizedCandidate
            ));
        }

        return $normalizedCurrent;
    }

    /** @return array<string, UpgradeTarget> */
    private function copyTargets(): array
    {
        return array_map(
            static fn (UpgradeTarget $target): UpgradeTarget => new UpgradeTarget($target->package(), $target->constraint()),
            $this->targets
        );
    }

    private function normalizePhpVersion(string $version): string
    {
        $version = trim($version);

        if (!preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/i', $version, $matches)) {
            throw new \InvalidArgumentException(sprintf(
                'PHP target "%s" must be an exact major, major.minor, or major.minor.patch version.',
                $version
            ));
        }

        return sprintf(
            '%d.%d.%d',
            (int) $matches[1],
            isset($matches[2]) ? (int) $matches[2] : 0,
            isset($matches[3]) ? (int) $matches[3] : 0
        );
    }

    private function validatePackage(string $package): void
    {
        $composerPackage = '[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*';
        $platformPackage = '(?:php(?:-64bit|-ipv6)?|ext-[a-z0-9_.-]+|lib-[a-z0-9_.-]+|composer(?:-plugin-api|-runtime-api)?)';

        if ($package === '' || !preg_match('~^(?:' . $composerPackage . '|' . $platformPackage . ')$~D', $package)) {
            throw new \InvalidArgumentException(sprintf('Invalid Composer target package "%s".', $package));
        }
    }

    private function validateConstraint(string $package, string $constraint): void
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
