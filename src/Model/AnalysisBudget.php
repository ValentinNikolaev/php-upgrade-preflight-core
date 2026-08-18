<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

/**
 * The declared analysis budgets, owned by the layer that serializes them.
 *
 * {@see StagedResolution::toArray()} publishes every constant below in the
 * report's `budgets` block, so this class lives beside the report models rather
 * than in the analysis layer that enforces them.
 * {@see \PhpUpgradePreflight\Core\Analysis\StagedAnalysisPolicy} is the
 * analysis-facing alias and carries the same values.
 *
 * Two kinds of limit are published together, and the serialized block does not
 * yet distinguish them:
 *
 * - Enforced. `MAX_HOPS`, `MAX_COMPOSER_PROCESSES`, `SCENARIO_TIMEOUT_SECONDS`,
 *   `STAGE_TIMEOUT_SECONDS`, and `AGGREGATE_TIMEOUT_SECONDS` are checked before
 *   and during staged Composer execution, and a breach stops the chain with a
 *   recorded stop reason. The pre-attempt check reserves what a whole attempt can
 *   cost — one scenario plus one `composer prohibits` diagnostic per probed
 *   target, all charged to the same measured window — so the stage and aggregate
 *   deadlines are real upper bounds rather than per-scenario approximations.
 *   `STAGE_TIMEOUT_SECONDS` is therefore what limits how many attempts a stage can
 *   afford; `MAX_ATTEMPTS_PER_STAGE` caps the attempt list without promising that
 *   every capped attempt fits, and `MAX_SCENARIOS` is its product with `MAX_HOPS`.
 * - Advisory. `MEMORY_BUDGET_BYTES`, `JSON_REPORT_BUDGET_BYTES`, and
 *   `MARKDOWN_REPORT_BUDGET_BYTES` are declared targets only. Nothing in the
 *   analyzer measures memory or report size against them; the worst-supported
 *   chain integration test asserts the report sizes externally. Separating them
 *   in the serialized `budgets` block would change the frozen v0.8 report
 *   schema and its contract fixtures, so the distinction is documented here
 *   until that schema decision is taken.
 */
final class AnalysisBudget
{
    public const MAX_HOPS = 6;
    public const MAX_ATTEMPTS_PER_STAGE = 3;
    public const MAX_SCENARIOS = self::MAX_HOPS * self::MAX_ATTEMPTS_PER_STAGE;
    public const MAX_COMPOSER_PROCESSES = 128;
    public const SCENARIO_TIMEOUT_SECONDS = 300;
    public const STAGE_TIMEOUT_SECONDS = self::MAX_ATTEMPTS_PER_STAGE * self::SCENARIO_TIMEOUT_SECONDS;
    public const AGGREGATE_TIMEOUT_SECONDS = 1800;
    public const MEMORY_BUDGET_BYTES = 268435456;
    public const JSON_REPORT_BUDGET_BYTES = 524288;
    public const MARKDOWN_REPORT_BUDGET_BYTES = 262144;
}
