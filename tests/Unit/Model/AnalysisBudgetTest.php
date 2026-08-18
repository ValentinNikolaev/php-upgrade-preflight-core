<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Analysis\StagedAnalysisPolicy;
use PhpUpgradePreflight\Core\Model\AnalysisBudget;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PHPUnit\Framework\TestCase;

final class AnalysisBudgetTest extends TestCase
{
    public function testTheAnalysisFacingPolicyPublishesTheSameBudgets(): void
    {
        $budgets = (new \ReflectionClass(AnalysisBudget::class))->getConstants();
        $policy = (new \ReflectionClass(StagedAnalysisPolicy::class))->getConstants();

        self::assertNotSame([], $budgets);
        self::assertSame($budgets, $policy);
    }

    public function testTheSerializedBudgetBlockMatchesTheFrozenContract(): void
    {
        $canonical = StagedResolution::skipped('stage_target_provider_unavailable')->toArray();

        self::assertSame([
            'max_hops' => 6,
            'max_attempts_per_stage' => 3,
            'max_scenarios' => 18,
            'max_composer_processes' => 128,
            'scenario_timeout_seconds' => 300,
            'stage_timeout_seconds' => 900,
            'aggregate_timeout_seconds' => 1800,
            'memory_bytes' => 268435456,
            'json_report_bytes' => 524288,
            'markdown_report_bytes' => 262144,
        ], $canonical['budgets']);
    }
}
