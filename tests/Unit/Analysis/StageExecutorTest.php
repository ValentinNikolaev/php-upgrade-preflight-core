<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\BlockerGrouper;
use PhpUpgradePreflight\Core\Analysis\LockDiffBuilder;
use PhpUpgradePreflight\Core\Analysis\StageAttemptPlanner;
use PhpUpgradePreflight\Core\Analysis\StageBlockerRegistry;
use PhpUpgradePreflight\Core\Analysis\StageExecutor;
use PhpUpgradePreflight\Core\Analysis\StagedAnalysisPolicy;
use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

/**
 * The staged time budgets are stop-gates, not hard kills.
 *
 * The v0.3 contract is explicit: "an attempt starts only when its full effective
 * scenario timeout fits within the remaining stage and aggregate budgets;
 * measured diagnostic and cleanup time can exhaust either budget and prevent
 * subsequent work". So the pre-attempt gate reserves one scenario, an attempt
 * already admitted may overrun by the diagnostics it runs, and the post-attempt
 * check is what ends the chain. These cases pin both halves of that contract.
 */
final class StageExecutorTest extends TestCase
{
    public function testAnAttemptStartsWheneverABareScenarioStillFitsTheStageBudget(): void
    {
        [$project, $request, $platform] = $this->context();
        $now = 0.0;
        $scenarioProcesses = 0;
        // Each solver-failing attempt spends 200s solving plus 160s on each of the two
        // target diagnostics, so it costs 520s against a 900s stage.
        $executor = $this->executor($this->scriptedRunner(
            [
                ['scenario' => 200.0, 'diagnostic' => 160.0, 'resolved' => false],
                ['scenario' => 200.0, 'diagnostic' => 160.0, 'resolved' => false],
            ],
            $now,
            $scenarioProcesses
        ));

        $outcome = $executor->execute(
            $this->stage(0, 1),
            $project,
            $platform,
            $request,
            new EvidenceLedger()
        );
        $canonical = $outcome->analysis()->toArray();

        // After the first attempt 380s remain, which cannot pay for a second full
        // attempt but does clear the contract's bare-scenario gate, so it starts.
        self::assertGreaterThan(
            StagedAnalysisPolicy::SCENARIO_TIMEOUT_SECONDS * 1000,
            StagedAnalysisPolicy::STAGE_TIMEOUT_SECONDS * 1000 - 520000
        );
        self::assertSame(2, $scenarioProcesses);
        self::assertCount(2, $canonical['attempts']);
    }

    public function testAnAdmittedAttemptThatOverrunsTheStageStopsTheChainAfterwards(): void
    {
        [$project, $request, $platform] = $this->context();
        $now = 0.0;
        $scenarioProcesses = 0;
        // The gate admits this attempt on its bare 300s scenario, but the two target
        // diagnostics it then runs cost 350s each, so the attempt spends 1000s.
        $executor = $this->executor($this->scriptedRunner(
            [['scenario' => 300.0, 'diagnostic' => 350.0, 'resolved' => false]],
            $now,
            $scenarioProcesses
        ));

        $outcome = $executor->execute(
            $this->stage(0, 1),
            $project,
            $platform,
            $request,
            new EvidenceLedger()
        );

        // The overshoot is bounded by the attempt already in flight, and the
        // post-attempt check ends the chain rather than absorbing it.
        self::assertSame(1000000, $outcome->analysis()->toArray()['duration_ms']);
        self::assertGreaterThan(
            StagedAnalysisPolicy::STAGE_TIMEOUT_SECONDS * 1000,
            1000000
        );
        self::assertSame(1, $scenarioProcesses);
        self::assertSame('stage_timeout', $outcome->stopReason());
        self::assertSame(StagedResolution::UNKNOWN, $outcome->status());
    }

    public function testAStageIsRefusedWhenTheAggregateBudgetCannotFitABareScenario(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $now = 0.0;
        $scenarioProcesses = 0;
        $executor = $this->executor($this->scriptedRunner(
            [
                ['scenario' => 800.0, 'diagnostic' => 0.0, 'resolved' => true],
                ['scenario' => 800.0, 'diagnostic' => 0.0, 'resolved' => true],
            ],
            $now,
            $scenarioProcesses
        ));

        $first = $executor->execute($this->stage(0, 1), $project, $platform, $request, $ledger);
        $second = $executor->execute(
            $this->stage(1, 2),
            $first->selectedState() ?? $project,
            $platform,
            $request,
            $ledger
        );
        $third = $executor->execute(
            $this->stage(2, 3),
            $second->selectedState() ?? $project,
            $platform,
            $request,
            $ledger
        );
        $remainingAggregateMs = StagedAnalysisPolicy::AGGREGATE_TIMEOUT_SECONDS * 1000 - 1600000;

        self::assertSame(StagedResolution::FEASIBLE, $first->status());
        self::assertSame(StagedResolution::FEASIBLE, $second->status());
        self::assertLessThan(
            StagedAnalysisPolicy::SCENARIO_TIMEOUT_SECONDS * 1000,
            $remainingAggregateMs,
            'The third stage may not begin without room for a full scenario.'
        );
        self::assertSame(2, $scenarioProcesses);
        self::assertSame([], $third->analysis()->toArray()['attempts']);
        self::assertSame('aggregate_timeout', $third->stopReason());
        self::assertSame(StagedResolution::UNKNOWN, $third->status());
    }

    private function executor(ComposerScenarioRunner $runner): StageExecutor
    {
        return new StageExecutor(
            $runner,
            new BlockerGrouper(),
            new LockDiffBuilder(),
            new StageAttemptPlanner(),
            new StageBlockerRegistry()
        );
    }

    /**
     * A runner whose synthetic clock only moves inside a Composer process, so every
     * scripted second is charged to the attempt that spent it. Diagnostics advance
     * the same clock, which is exactly what the reservation has to anticipate.
     *
     * @param list<array{scenario: float, diagnostic: float, resolved: bool}> $script
     */
    private function scriptedRunner(array $script, float &$now, int &$scenarioProcesses): ComposerScenarioRunner
    {
        /** @var array{scenario: float, diagnostic: float, resolved: bool} $step */
        $step = ['scenario' => 0.0, 'diagnostic' => 0.0, 'resolved' => false];

        return new ComposerScenarioRunner(
            null,
            null,
            static function (array $command) use (&$script, &$step, &$now, &$scenarioProcesses): array {
                if (in_array('prohibits', $command, true)) {
                    $now += $step['diagnostic'];

                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic staged diagnostic.'];
                }

                $next = array_shift($script);
                if (!is_array($next)) {
                    throw new \LogicException('No scripted staged scenario remains.');
                }
                $step = $next;
                ++$scenarioProcesses;
                $now += $step['scenario'];
                if ($step['resolved']) {
                    return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
                }

                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => implode("\n", [
                        'Your requirements could not be resolved to an installable set of packages.',
                        '- vendor/blocker 1.0.0 requires ext-scripted * -> it is missing from your system.',
                    ]),
                ];
            },
            static fn (): string => '2.8.12',
            static function () use (&$now): float {
                return $now;
            }
        );
    }

    /** @return array{ProjectState, UpgradeRequest, TargetPlatform} */
    private function context(): array
    {
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/project-isolation';
        $project = new ProjectState(
            $projectPath,
            new ComposerJson(['name' => 'fixture/project', 'require' => ['vendor/framework' => '^0.0']]),
            new ComposerLock(['packages' => []])
        );
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('vendor/framework', '^7.0')],
            '8.1',
            '8.3'
        );

        return [$project, $request, TargetPlatform::fromRequest($request, $project, [], '8.3.0')];
    }

    /**
     * @param list<UpgradeTarget> $remediations
     * @param array<string, list<string>> $remediationEvidence
     */
    private function stage(
        int $from,
        int $to,
        array $remediations = [],
        array $remediationEvidence = []
    ): FrameworkStageTarget {
        return new FrameworkStageTarget(
            sprintf('fixture-%d-to-%d', $from, $to),
            'fixture',
            $from,
            $to,
            new UpgradeTargetSet([new UpgradeTarget('vendor/framework', '^' . $to . '.0')], '8.3.0'),
            '8.3.0',
            $remediations,
            $remediationEvidence,
            ['stage-evidence']
        );
    }
}
