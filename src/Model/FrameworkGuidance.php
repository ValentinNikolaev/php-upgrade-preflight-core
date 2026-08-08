<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class FrameworkGuidance
{
    public const SUPPORTED = 'supported';
    public const PARTIALLY_SUPPORTED = 'partially_supported';
    public const UNSUPPORTED = 'unsupported';

    private string $framework;
    private ?int $sourceMajor;
    private ?int $targetMajor;
    private string $status;
    /** @var list<FrameworkHop> */
    private array $hops;
    /** @var list<string> */
    private array $uncertainties;
    /** @var list<string> */
    private array $evidence;

    /** @param list<FrameworkHop> $hops @param list<string> $uncertainties @param list<string> $evidence */
    public function __construct(
        string $framework,
        ?int $sourceMajor,
        ?int $targetMajor,
        string $status,
        array $hops,
        array $uncertainties,
        array $evidence
    ) {
        if (trim($framework) === '') {
            throw new \InvalidArgumentException('A framework-guidance assessment requires a framework name.');
        }
        if (($sourceMajor !== null && $sourceMajor < 0) || ($targetMajor !== null && $targetMajor < 0)) {
            throw new \InvalidArgumentException('Framework majors must be non-negative when known.');
        }
        if (!in_array($status, [self::SUPPORTED, self::PARTIALLY_SUPPORTED, self::UNSUPPORTED], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported framework-guidance status "%s".', $status));
        }
        foreach ($hops as $hop) {
            if (!$hop instanceof FrameworkHop) {
                throw new \InvalidArgumentException('Framework guidance hops must be FrameworkHop instances.');
            }
        }
        if ($status === self::SUPPORTED && $hops === []) {
            throw new \InvalidArgumentException('Supported framework guidance requires at least one covered hop.');
        }
        if ($status !== self::SUPPORTED && $uncertainties === []) {
            throw new \InvalidArgumentException('Incomplete framework guidance must explain its uncertainty.');
        }
        if ($evidence === []) {
            throw new \InvalidArgumentException('Framework guidance must reference evidence.');
        }

        $this->validateCoverage($sourceMajor, $targetMajor, $status, $hops);

        $this->framework = strtolower(trim($framework));
        $this->sourceMajor = $sourceMajor;
        $this->targetMajor = $targetMajor;
        $this->status = $status;
        $this->hops = array_values($hops);
        $this->uncertainties = array_values(array_unique($uncertainties));
        $this->evidence = array_values(array_unique($evidence));
    }

    public function framework(): string
    {
        return $this->framework;
    }

    public function sourceMajor(): ?int
    {
        return $this->sourceMajor;
    }

    public function targetMajor(): ?int
    {
        return $this->targetMajor;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return list<FrameworkHop> */
    public function hops(): array
    {
        return $this->hops;
    }

    /** @return list<array{from_major: int, to_major: int}> */
    public function supportedHopReferences(): array
    {
        return array_values(array_map(
            static fn (FrameworkHop $hop): array => $hop->reference(),
            array_filter($this->hops, static fn (FrameworkHop $hop): bool => $hop->isSupported())
        ));
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /**
     * @return array{
     *   framework: string,
     *   source_major: ?int,
     *   target_major: ?int,
     *   status: string,
     *   hops: list<array{from_major: int, to_major: int, status: string, rule_pack: ?string, evidence: list<string>}>,
     *   uncertainties: list<string>,
     *   evidence: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'framework' => $this->framework,
            'source_major' => $this->sourceMajor,
            'target_major' => $this->targetMajor,
            'status' => $this->status,
            'hops' => array_map(static fn (FrameworkHop $hop): array => $hop->toArray(), $this->hops),
            'uncertainties' => $this->uncertainties,
            'evidence' => $this->evidence,
        ];
    }

    /** @param list<FrameworkHop> $hops */
    private function validateCoverage(?int $sourceMajor, ?int $targetMajor, string $status, array $hops): void
    {
        if (($sourceMajor === null || $targetMajor === null) && $hops !== []) {
            throw new \InvalidArgumentException('Ambiguous framework transitions must not contain guessed hops.');
        }
        if ($hops === []) {
            return;
        }
        if ($sourceMajor === null || $targetMajor === null || $sourceMajor >= $targetMajor) {
            throw new \InvalidArgumentException('Framework guidance hops require a known ascending source and target.');
        }
        if ($hops[0]->fromMajor() !== $sourceMajor || $hops[count($hops) - 1]->toMajor() !== $targetMajor) {
            throw new \InvalidArgumentException('Framework guidance hops must cover the declared source-to-target interval.');
        }

        $encounteredGap = false;
        $supportedCount = 0;
        foreach ($hops as $index => $hop) {
            if ($index > 0 && $hops[$index - 1]->toMajor() !== $hop->fromMajor()) {
                throw new \InvalidArgumentException('Framework guidance hops must form a contiguous ordered path.');
            }
            if ($hop->isSupported()) {
                if ($encounteredGap) {
                    throw new \InvalidArgumentException('Framework guidance must ignore implemented rule packs after the first missing hop.');
                }
                ++$supportedCount;
            } else {
                $encounteredGap = true;
            }
        }

        if ($status === self::SUPPORTED && $supportedCount !== count($hops)) {
            throw new \InvalidArgumentException('Supported framework guidance cannot contain a missing hop.');
        }
        if ($status === self::PARTIALLY_SUPPORTED && ($supportedCount === 0 || !$encounteredGap)) {
            throw new \InvalidArgumentException('Partially supported guidance requires a nonempty covered prefix followed by a gap.');
        }
        if ($status === self::UNSUPPORTED && $supportedCount > 0) {
            throw new \InvalidArgumentException('Unsupported framework guidance cannot contain a covered prefix.');
        }
    }
}
