<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PhpUpgradePreflight\Core\Support\PathExposurePolicy;
use PHPUnit\Framework\TestCase;

final class ScenarioResultTest extends TestCase
{
    public function testItRejectsAnOutcomeWithTheWrongFailureType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires failure type "operational"');

        new ScenarioResult(
            $this->scenario(),
            1,
            '',
            'Timed out.',
            null,
            null,
            ScenarioResult::FAILURE_SOLVER,
            null,
            [],
            0,
            null,
            [],
            ScenarioResult::OUTCOME_TIMEOUT
        );
    }

    public function testItRejectsAFailedOutcomeContainingALock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot contain a candidate lock');

        new ScenarioResult(
            $this->scenario(),
            0,
            '',
            'Cleanup failed.',
            new \PhpUpgradePreflight\Core\Model\ComposerLock([]),
            null,
            ScenarioResult::FAILURE_OPERATIONAL,
            null,
            [],
            0,
            null,
            [],
            ScenarioResult::OUTCOME_CLEANUP_FAILURE
        );
    }

    public function testItRejectsAFailedOutcomeContainingACandidateProjectState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot contain a candidate project state');

        new ScenarioResult(
            $this->scenario(),
            1,
            '',
            'Process failed.',
            null,
            null,
            ScenarioResult::FAILURE_OPERATIONAL,
            null,
            [],
            0,
            null,
            [],
            ScenarioResult::OUTCOME_PROCESS_FAILURE,
            false,
            new \PhpUpgradePreflight\Core\Model\ProjectState(
                __DIR__,
                new \PhpUpgradePreflight\Core\Model\ComposerJson([]),
                new \PhpUpgradePreflight\Core\Model\ComposerLock([])
            )
        );
    }

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

        self::assertLessThanOrEqual(4000, strlen($serialized['stdout_excerpt']));
        self::assertStringStartsWith(str_repeat('o', 100), $serialized['stdout_excerpt']);
        self::assertStringEndsWith('[TRUNCATED: 4001 bytes of output omitted]', $serialized['stdout_excerpt']);
        self::assertLessThanOrEqual(4000, strlen($serialized['stderr_excerpt']));
        self::assertStringStartsWith(str_repeat('e', 100), $serialized['stderr_excerpt']);
        self::assertStringEndsWith('[TRUNCATED: 4002 bytes of output omitted]', $serialized['stderr_excerpt']);
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

    public function testWorkspacePathIsOnlyExposedByExplicitDebugSerialization(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-private-root';
        $default = new ScenarioResult(
            $this->scenario(),
            1,
            '',
            'Cleanup failed.',
            null,
            $path,
            ScenarioResult::FAILURE_OPERATIONAL,
            null,
            [],
            0,
            null,
            [],
            ScenarioResult::OUTCOME_CLEANUP_FAILURE
        );
        $debug = new ScenarioResult(
            $this->scenario(),
            1,
            '',
            'Debug failure.',
            null,
            $path,
            ScenarioResult::FAILURE_OPERATIONAL,
            null,
            [],
            0,
            null,
            [],
            ScenarioResult::OUTCOME_PROCESS_FAILURE,
            true
        );

        self::assertSame($path, $default->tempPath());
        self::assertSame(PathExposurePolicy::ANALYZER_WORKSPACE, $default->toArray()['temp_path']);
        self::assertSame($path, $debug->toArray()['temp_path']);
    }

    public function testWorkspacePathIsRemovedFromStoredOutputCommandsAndDiagnostics(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-private-root';
        $diagnostic = new ComposerDiagnostic(
            'fixture/dependency',
            '^2.0',
            ['composer', 'prohibits', $path . DIRECTORY_SEPARATOR . 'package'],
            1,
            'Diagnostic stdout in ' . $path,
            'Diagnostic stderr in ' . $path
        );
        $result = new ScenarioResult(
            $this->scenario(),
            1,
            'Scenario stdout in ' . $path,
            'Scenario stderr in ' . $path,
            null,
            $path,
            ScenarioResult::FAILURE_OPERATIONAL,
            null,
            ['composer', '--working-dir=' . $path],
            0,
            null,
            [$diagnostic],
            ScenarioResult::OUTCOME_PROCESS_FAILURE,
            true
        );

        $serialized = json_encode($result->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($path, $result->stdout());
        self::assertStringNotContainsString($path, $result->stderr());
        self::assertSame($path, $result->tempPath());
        self::assertStringContainsString($path, $result->toArray()['temp_path']);
        self::assertSame(6, substr_count($serialized, PathExposurePolicy::ANALYZER_WORKSPACE));
    }

    private function scenario(): Scenario
    {
        return new Scenario(
            'exact-target',
            new UpgradeTargetSet([new UpgradeTarget('fixture/dependency', '^2.0')])
        );
    }
}
