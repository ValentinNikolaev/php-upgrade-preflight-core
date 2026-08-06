<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class ScenarioResultTest extends TestCase
{
    public function testSerializedOutputExcerptsAreBoundedToFourThousandBytes(): void
    {
        $scenario = new Scenario(
            'exact-target',
            new UpgradeTargetSet([new UpgradeTarget('fixture/dependency', '^2.0')])
        );
        $result = new ScenarioResult(
            $scenario,
            2,
            str_repeat('o', 4001),
            str_repeat('e', 4002),
            null,
            null,
            ScenarioResult::FAILURE_SOLVER
        );

        $serialized = $result->toArray();

        self::assertSame(4000, strlen($serialized['stdout_excerpt']));
        self::assertSame(str_repeat('o', 4000), $serialized['stdout_excerpt']);
        self::assertSame(4000, strlen($serialized['stderr_excerpt']));
        self::assertSame(str_repeat('e', 4000), $serialized['stderr_excerpt']);
    }

    public function testSerializedDiagnosticOutputExcerptsAreBoundedToFourThousandBytes(): void
    {
        $scenario = new Scenario(
            'exact-target',
            new UpgradeTargetSet([new UpgradeTarget('fixture/dependency', '^2.0')])
        );
        $diagnostic = new ComposerDiagnostic(
            'fixture/dependency',
            '^2.0',
            ['composer', 'prohibits', 'fixture/dependency', '^2.0'],
            0,
            str_repeat('o', 4001),
            str_repeat('e', 4002)
        );
        $result = new ScenarioResult(
            $scenario,
            2,
            '',
            'Solver failed.',
            null,
            null,
            ScenarioResult::FAILURE_SOLVER,
            null,
            [],
            0,
            null,
            [$diagnostic]
        );

        $serialized = $result->toArray();

        self::assertSame(4000, strlen($serialized['diagnostics'][0]['stdout_excerpt']));
        self::assertSame(4000, strlen($serialized['diagnostics'][0]['stderr_excerpt']));
    }
}
