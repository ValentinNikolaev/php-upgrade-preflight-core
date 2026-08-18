<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class UpgradeTargetSet implements \Countable, \IteratorAggregate
{
    /** @var array<string, UpgradeTarget> */
    private array $targets;
    private ?string $targetPhp;
    /** @var list<UpgradeTarget> */
    private array $canonicalTargets;

    /**
     * Individual targets normalize and validate themselves; this collection only deduplicates,
     * merges the PHP target, rejects contradictions, and fixes the canonical order.
     *
     * @param list<UpgradeTarget> $targets
     */
    public function __construct(array $targets, ?string $targetPhp = null)
    {
        $normalized = [];

        foreach ($targets as $index => $target) {
            if (!$target instanceof UpgradeTarget) {
                throw new \InvalidArgumentException(sprintf('Upgrade target at index %d must be an UpgradeTarget.', $index));
            }

            $package = $target->package();
            $constraint = $target->constraint();

            if ($package === 'php') {
                $targetPhp = $this->mergePhpTarget($targetPhp, $constraint);
                continue;
            }

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

            $normalized[$package] = $target;
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

        if ($targetPhp !== null) {
            $normalized['php'] = new UpgradeTarget('php', $targetPhp);
            ksort($normalized, SORT_STRING);
        }

        $this->canonicalTargets = array_values($normalized);
    }

    /** @return list<UpgradeTarget> */
    public function packageTargets(): array
    {
        return array_values($this->targets);
    }

    public function targetPhp(): ?string
    {
        return $this->targetPhp;
    }

    /** @return list<UpgradeTarget> */
    public function all(): array
    {
        return $this->canonicalTargets;
    }

    /** @return list<array{package: string, constraint: string}> */
    public function toArray(): array
    {
        return array_map(static fn (UpgradeTarget $target): array => $target->toArray(), $this->canonicalTargets);
    }

    public function count(): int
    {
        return count($this->canonicalTargets);
    }

    /** @return \Traversable<int, UpgradeTarget> */
    public function getIterator(): \Traversable
    {
        yield from $this->canonicalTargets;
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
}
