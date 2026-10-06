<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\StagedResolution;

/**
 * The outcome of resolving a staged plan: either a finished skipped
 * {@see StagedResolution}, or the validated, budget-approved stage chain that
 * Composer execution may run.
 *
 * The two shapes are mutually exclusive, so no caller can execute a plan that
 * was rejected before Composer ran.
 */
final class StagePlanResolution
{
    private ?StagedResolution $skipped;
    private ?string $provider;
    /** @var list<FrameworkStageTarget> */
    private array $stages;
    /** @var list<string> */
    private array $evidence;

    /**
     * @param list<FrameworkStageTarget> $stages
     * @param list<string> $evidence
     */
    private function __construct(?StagedResolution $skipped, ?string $provider, array $stages, array $evidence)
    {
        $this->skipped = $skipped;
        $this->provider = $provider;
        $this->stages = $stages;
        $this->evidence = $evidence;
    }

    public static function skipped(StagedResolution $resolution): self
    {
        return new self($resolution, null, [], []);
    }

    /**
     * @param list<FrameworkStageTarget> $stages
     * @param list<string> $evidence
     */
    public static function executable(string $provider, array $stages, array $evidence): self
    {
        return new self(null, $provider, $stages, $evidence);
    }

    public function isSkipped(): bool
    {
        return $this->skipped !== null;
    }

    public function skippedResolution(): StagedResolution
    {
        if ($this->skipped === null) {
            throw new \LogicException('An executable staged plan carries no skipped resolution.');
        }

        return $this->skipped;
    }

    public function provider(): string
    {
        if ($this->provider === null) {
            throw new \LogicException('A skipped staged plan carries no executable provider.');
        }

        return $this->provider;
    }

    /** @return list<FrameworkStageTarget> */
    public function stages(): array
    {
        return $this->stages;
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }
}
