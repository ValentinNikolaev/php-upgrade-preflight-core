<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\BlockerGrouper;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class BlockerGrouperTest extends TestCase
{
    /**
     * @dataProvider solverOutputProvider
     */
    public function testItClassifiesSolverOutputAndCreatesEvidence(string $output, string $expectedType, string $expectedSubject): void
    {
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario(), 2, '', $output, null, null, ScenarioResult::FAILURE_SOLVER),
        ], $evidence);

        self::assertCount(1, $blockers);
        self::assertSame($expectedType, $blockers[0]->type());
        self::assertSame($expectedSubject, $blockers[0]->subject());
        self::assertSame(['solver-1'], $blockers[0]->evidence());
        self::assertCount(1, $evidence->all());
        self::assertSame('solver-1', $evidence->all()[0]->id());
        self::assertSame(2, $evidence->all()[0]->context()['exit_code']);
        self::assertSame($output, $evidence->all()[0]->context()['output_excerpt']);
    }

    /** @return list<array{string, string, string}> */
    public function solverOutputProvider(): array
    {
        return [
            ['vendor/package requires php >=8.2', 'php-platform-too-low', 'php'],
            ['Could not find package vendor/missing', 'package-not-found', 'composer'],
            ['The package does not match your minimum-stability', 'minimum-stability-conflict', 'composer'],
            ['- Root composer.json requires Vendor/Package ^2.0', 'root-constraint-conflict', 'vendor/package'],
            ['Your requirements could not be resolved.', 'unknown-composer-failure', 'composer'],
        ];
    }

    public function testAnySuccessfulScenarioSuppressesFallbackBlockers(): void
    {
        $scenario = $this->scenario();
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($scenario, 2, '', 'vendor/package requires php >=8.2', null, null, ScenarioResult::FAILURE_SOLVER),
            new ScenarioResult($scenario, 0, 'Resolved.', '', new ComposerLock([])),
        ], $evidence);

        self::assertSame([], $blockers);
        self::assertSame([], $evidence->all());
    }

    public function testOperationalFailuresDoNotBecomeDependencyBlockers(): void
    {
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario(), 1, '', 'Composer executable unavailable.', null, null, ScenarioResult::FAILURE_OPERATIONAL),
        ], $evidence);

        self::assertSame([], $blockers);
        self::assertSame([], $evidence->all());
    }

    private function scenario(): Scenario
    {
        return new Scenario('test', new UpgradeTargetSet([new UpgradeTarget('vendor/package', '^2.0')]));
    }
}
