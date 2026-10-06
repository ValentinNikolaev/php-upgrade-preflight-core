<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class FrameworkStagePlan
{
    public const REASON_MISSING_TARGET = 'missing_target';
    public const REASON_AMBIGUOUS_TRANSITION = 'ambiguous_transition';
    public const REASON_GUIDANCE_GAP = 'guidance_gap';
    public const REASON_UNSUPPORTED_TRANSITION = 'unsupported_transition';
    public const REASON_ANALYSIS_PHP_UNAVAILABLE = 'analysis_php_unavailable';

    private string $provider;
    /** @var list<FrameworkStageTarget> */
    private array $stages;
    private ?string $unavailableReason;
    /** @var list<string> */
    private array $evidence;

    /**
     * @param list<FrameworkStageTarget> $stages
     * @param list<string> $evidence
     */
    public function __construct(
        string $provider,
        array $stages,
        ?string $unavailableReason = null,
        array $evidence = []
    ) {
        if ($provider === '') {
            throw new \InvalidArgumentException('A framework stage plan must name its provider.');
        }
        foreach ($stages as $stage) {
            if (!$stage instanceof FrameworkStageTarget) {
                throw new \InvalidArgumentException('Framework stage plans may contain only FrameworkStageTarget instances.');
            }
        }
        foreach ($evidence as $reference) {
            if (!is_string($reference) || trim($reference) === '') {
                throw new \InvalidArgumentException('Framework stage-plan evidence IDs must be nonempty strings.');
            }
        }
        if ($stages !== [] && $unavailableReason !== null) {
            throw new \InvalidArgumentException('An available stage plan cannot also have an unavailable reason.');
        }
        if ($stages === [] && $unavailableReason === null) {
            throw new \InvalidArgumentException('An empty stage plan must explain why staged targets are unavailable.');
        }
        if ($unavailableReason !== null && !in_array($unavailableReason, self::unavailableReasons(), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported stage-plan reason "%s".', $unavailableReason));
        }

        $this->provider = $provider;
        $this->stages = array_values($stages);
        $this->unavailableReason = $unavailableReason;
        $this->evidence = array_values(array_unique($evidence));
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /** @return list<FrameworkStageTarget> */
    public function stages(): array
    {
        return $this->stages;
    }

    public function unavailableReason(): ?string
    {
        return $this->unavailableReason;
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    public function isAvailable(): bool
    {
        return $this->stages !== [];
    }

    /** @return list<string> */
    public static function unavailableReasons(): array
    {
        return [
            self::REASON_MISSING_TARGET,
            self::REASON_AMBIGUOUS_TRANSITION,
            self::REASON_GUIDANCE_GAP,
            self::REASON_UNSUPPORTED_TRANSITION,
            self::REASON_ANALYSIS_PHP_UNAVAILABLE,
        ];
    }
}
