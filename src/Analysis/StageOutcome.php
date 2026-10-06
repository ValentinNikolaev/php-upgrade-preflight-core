<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\StageAnalysis;

/**
 * What executing one stage produced: the recorded analysis, and the chain facts
 * the orchestrator needs to decide whether the next stage may run.
 *
 * A null selected state means the stage did not advance the candidate-state
 * chain, in which case the stop reason is always populated.
 */
final class StageOutcome
{
    private StageAnalysis $analysis;
    private ?ProjectState $selectedState;
    private string $status;
    private ?string $stopReason;
    private bool $hasPackageChanges;

    public function __construct(
        StageAnalysis $analysis,
        ?ProjectState $selectedState,
        string $status,
        ?string $stopReason,
        bool $hasPackageChanges
    ) {
        $this->analysis = $analysis;
        $this->selectedState = $selectedState;
        $this->status = $status;
        $this->stopReason = $stopReason;
        $this->hasPackageChanges = $hasPackageChanges;
    }

    public function analysis(): StageAnalysis
    {
        return $this->analysis;
    }

    public function selectedState(): ?ProjectState
    {
        return $this->selectedState;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    public function hasPackageChanges(): bool
    {
        return $this->hasPackageChanges;
    }
}
