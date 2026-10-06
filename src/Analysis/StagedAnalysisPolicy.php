<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\AnalysisBudget;

/**
 * The analysis-facing view of {@see AnalysisBudget}.
 *
 * The budgets are declared once in the model layer that serializes them into the
 * report; this alias keeps the analysis layer free of a Model-to-Analysis import
 * while preserving the constant names the staged analyzer reads. Values must stay
 * identical to {@see AnalysisBudget}; see that class for which limits are enforced
 * and which are advisory.
 */
final class StagedAnalysisPolicy
{
    public const MAX_HOPS = AnalysisBudget::MAX_HOPS;
    public const MAX_ATTEMPTS_PER_STAGE = AnalysisBudget::MAX_ATTEMPTS_PER_STAGE;
    public const MAX_SCENARIOS = AnalysisBudget::MAX_SCENARIOS;
    public const MAX_COMPOSER_PROCESSES = AnalysisBudget::MAX_COMPOSER_PROCESSES;
    public const SCENARIO_TIMEOUT_SECONDS = AnalysisBudget::SCENARIO_TIMEOUT_SECONDS;
    public const STAGE_TIMEOUT_SECONDS = AnalysisBudget::STAGE_TIMEOUT_SECONDS;
    public const AGGREGATE_TIMEOUT_SECONDS = AnalysisBudget::AGGREGATE_TIMEOUT_SECONDS;
    public const MEMORY_BUDGET_BYTES = AnalysisBudget::MEMORY_BUDGET_BYTES;
    public const JSON_REPORT_BUDGET_BYTES = AnalysisBudget::JSON_REPORT_BUDGET_BYTES;
    public const MARKDOWN_REPORT_BUDGET_BYTES = AnalysisBudget::MARKDOWN_REPORT_BUDGET_BYTES;
}
