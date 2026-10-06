<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\ScenarioSelector;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class ScenarioSelectorTest extends TestCase
{
    public function testPackageOnlyTargetsUseEveryPartialUpdateStrategyInStableOrder(): void
    {
        $uncertainties = [];

        $scenarios = (new ScenarioSelector())->select(
            new UpgradeTargetSet([
                new UpgradeTarget('vendor/z-package', '^2.0'),
                new UpgradeTarget('vendor/a-package', '^3.0'),
            ]),
            null,
            null,
            $uncertainties
        );

        self::assertSame(
            ['baseline-validation', 'exact-target', 'target-with-all-dependencies', 'minimal-changes'],
            $this->names($scenarios)
        );
        self::assertSame([], $uncertainties);
        self::assertSame(
            ['vendor/a-package', 'vendor/z-package'],
            array_map(
                static fn (UpgradeTarget $target): string => $target->package(),
                $scenarios[1]->targets()->packageTargets()
            )
        );
    }

    public function testPhpOnlyTargetsSkipTheRedundantAllDependenciesVariant(): void
    {
        $uncertainties = [];

        $scenarios = (new ScenarioSelector())->select(
            new UpgradeTargetSet([], '8.2'),
            '8.1',
            null,
            $uncertainties
        );

        self::assertSame(
            ['baseline-validation', 'exact-target', 'minimal-changes'],
            $this->names($scenarios)
        );
        self::assertSame([], $uncertainties);
    }

    public function testMixedTargetsKeepDistinctOrderingProbes(): void
    {
        $uncertainties = [];

        $scenarios = (new ScenarioSelector())->select(
            new UpgradeTargetSet([new UpgradeTarget('vendor/package', '^2.0')], '8.2'),
            '8.1',
            '8.0.30',
            $uncertainties
        );

        self::assertSame(
            [
                'baseline-validation',
                'exact-target',
                'target-with-all-dependencies',
                'minimal-changes',
                'target-platform-only',
                'staged-targets',
            ],
            $this->names($scenarios)
        );
        self::assertSame('8.1.0', $scenarios[5]->targets()->targetPhp());
        self::assertSame([], $uncertainties);
    }

    public function testStagedProbeIsSkippedWhenItMatchesTheFullAllDependenciesScenario(): void
    {
        $uncertainties = [];

        $scenarios = (new ScenarioSelector())->select(
            new UpgradeTargetSet([new UpgradeTarget('vendor/package', '^2.0')], '8.2'),
            'v8.2.0',
            '8.1',
            $uncertainties
        );

        self::assertSame(
            [
                'baseline-validation',
                'exact-target',
                'target-with-all-dependencies',
                'minimal-changes',
                'target-platform-only',
            ],
            $this->names($scenarios)
        );
        self::assertSame([], $uncertainties);
    }

    public function testUnknownCurrentPhpSkipsOnlyTheStagedProbeAndRecordsUncertainty(): void
    {
        $uncertainties = [];

        $scenarios = (new ScenarioSelector())->select(
            new UpgradeTargetSet([new UpgradeTarget('vendor/package', '^2.0')], '8.2'),
            null,
            null,
            $uncertainties
        );

        self::assertSame(
            [
                'baseline-validation',
                'exact-target',
                'target-with-all-dependencies',
                'minimal-changes',
                'target-platform-only',
            ],
            $this->names($scenarios)
        );
        self::assertSame(
            ['The staged package-target scenario was skipped because the current project PHP version is unknown; supply --from-php or configure config.platform.php.'],
            $uncertainties
        );
    }

    /**
     * @param list<Scenario> $scenarios
     * @return list<string>
     */
    private function names(array $scenarios): array
    {
        return array_map(static fn (Scenario $scenario): string => $scenario->name(), $scenarios);
    }
}
