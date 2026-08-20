<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Progress;

use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeReport;

final class AnalysisProgressEvent
{
    public const ANALYSIS_STARTED = 'analysis-started';
    public const PHASE_STARTED = 'phase-started';
    public const PHASE_COMPLETED = 'phase-completed';
    public const SCENARIO_STARTED = 'scenario-started';
    public const SCENARIO_COMPLETED = 'scenario-completed';
    public const ANALYSIS_COMPLETED = 'analysis-completed';
    public const ANALYSIS_FAILED = 'analysis-failed';

    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    private string $type;
    private ?string $phase;
    private ?string $scenario;
    private ?string $status;
    private ?string $outcome;

    private function __construct(
        string $type,
        ?string $phase = null,
        ?string $scenario = null,
        ?string $status = null,
        ?string $outcome = null
    ) {
        $this->type = $type;
        $this->phase = $phase;
        $this->scenario = $scenario;
        $this->status = $status;
        $this->outcome = $outcome;
    }

    public static function analysisStarted(): self
    {
        return new self(self::ANALYSIS_STARTED);
    }

    public static function phaseStarted(string $phase): self
    {
        AnalysisPhase::assertKnown($phase);

        return new self(self::PHASE_STARTED, $phase);
    }

    public static function phaseCompleted(string $phase, string $status = self::STATUS_SUCCEEDED): self
    {
        AnalysisPhase::assertKnown($phase);
        self::assertStatus($status);

        return new self(self::PHASE_COMPLETED, $phase, null, $status);
    }

    public static function scenarioStarted(Scenario $scenario): self
    {
        return new self(self::SCENARIO_STARTED, AnalysisPhase::COMPOSER_FEASIBILITY, $scenario->name());
    }

    public static function scenarioCompleted(ScenarioResult $result): self
    {
        return new self(
            self::SCENARIO_COMPLETED,
            AnalysisPhase::COMPOSER_FEASIBILITY,
            $result->scenario()->name(),
            $result->succeeded() ? self::STATUS_SUCCEEDED : self::STATUS_FAILED,
            $result->outcome()
        );
    }

    public static function analysisCompleted(UpgradeReport $report): self
    {
        return new self(
            self::ANALYSIS_COMPLETED,
            null,
            null,
            self::STATUS_SUCCEEDED,
            $report->resolutionStatus()
        );
    }

    public static function analysisFailed(): self
    {
        return new self(self::ANALYSIS_FAILED, null, null, self::STATUS_FAILED);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function phase(): ?string
    {
        return $this->phase;
    }

    public function scenario(): ?string
    {
        return $this->scenario;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function outcome(): ?string
    {
        return $this->outcome;
    }

    private static function assertStatus(string $status): void
    {
        if (!in_array($status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown analysis progress status "%s".', $status));
        }
    }
}
