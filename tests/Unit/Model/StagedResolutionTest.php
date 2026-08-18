<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\FrameworkStagePlan;
use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\ProjectStateFingerprint;
use PhpUpgradePreflight\Core\Model\RootConstraintChange;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceImpactFinding;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\StageAnalysis;
use PhpUpgradePreflight\Core\Model\StageAttempt;
use PhpUpgradePreflight\Core\Model\StageBlockerEntry;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class StagedResolutionTest extends TestCase
{
    public function testStagedModelsProjectStateEvidenceRiskAndSourceAssessments(): void
    {
        $target = $this->target();
        $fingerprint = $this->fingerprint();
        $change = new RootConstraintChange(
            'vendor/remediation',
            'updated',
            '^1.0',
            '^2.0',
            'Required by the staged transition.',
            ['root-change-evidence']
        );
        $scenario = new Scenario('fixture-attempt', $target->targets());
        $result = new ScenarioResult($scenario, 0, 'Resolved.', '', new ComposerLock(['packages' => []]));
        $attempt = new StageAttempt(
            1,
            'root_constraint_remediation',
            [$change],
            $result,
            $fingerprint,
            $fingerprint,
            ['blocker-1', 'blocker-1'],
            ['attempt-evidence', 'attempt-evidence']
        );
        $selected = $attempt->withSelected();
        $finding = new CompatibilityFinding(
            'fixture',
            'high',
            'Review the high-impact framework change.',
            ['finding-evidence'],
            [['from_major' => 1, 'to_major' => 2]]
        );
        $impact = new SourceImpactFinding(
            'vendor/framework',
            'exact',
            'framework_rule',
            'The source calls a changed framework API.',
            'high',
            [new SourceUsage('src/Fixture.php', 'Fixture::run', 'static_call', ['finding-evidence'], 12)],
            ['finding-evidence']
        );
        $packageChange = new PackageChange('vendor/framework', 'upgraded', '1.0.0', '2.0.0', true);
        $stage = new StageAnalysis(
            $target,
            StageAnalysis::EXECUTED,
            StagedResolution::FEASIBLE_WITH_CHANGES,
            [$selected],
            1,
            $fingerprint,
            $fingerprint,
            $fingerprint,
            [$packageChange]
        );
        $assessedStage = $stage->withReportingAssessment(
            [$finding],
            [$impact->withStageIds(['fixture-1-to-2'])],
            [],
            new RiskSummary('high', ['Stage fixture-1-to-2 has a high-impact finding.']),
            new EffortEstimate([4, 12], 'low', ['source_changes' => [1, 4]], [
                'Stage fixture-1-to-2 uses original-source evidence.',
            ]),
            ['[fixture-1-to-2] Review the high-impact framework change.'],
            []
        );
        $blocker = new Blocker(
            'transitive-package-conflict',
            'vendor/framework',
            'A package blocks the stage.',
            'high',
            ['blocker-evidence'],
            '^2.0',
            'vendor/blocker',
            '1.0.0',
            '^1.0',
            ['vendor/blocker', 'vendor/framework'],
            ['Upgrade vendor/blocker.']
        );
        $registryEntry = StageBlockerEntry::detected('fixture-1-to-2', 1, 'fixture-attempt', $blocker);
        $resolution = new StagedResolution(
            StagedResolution::EVALUATED,
            StagedResolution::FEASIBLE_WITH_CHANGES,
            'fixture',
            [$stage],
            [$registryEntry],
            null,
            ['resolution-evidence']
        );
        $assessed = $resolution->withSourceAssessments([$finding], [$impact]);

        self::assertSame(1, $attempt->number());
        self::assertSame($result, $attempt->scenario());
        self::assertSame(['blocker-1'], $attempt->blockerIds());
        self::assertSame(['attempt-evidence', 'root-change-evidence'], $attempt->evidenceReferences());
        self::assertTrue($selected->toArray()['selected']);
        self::assertSame($target, $stage->target());
        self::assertSame(StageAnalysis::EXECUTED, $stage->executionState());
        self::assertSame(StagedResolution::FEASIBLE_WITH_CHANGES, $stage->resolutionStatus());
        self::assertSame([$selected], $stage->attempts());
        self::assertSame($fingerprint, $stage->outputState());
        self::assertSame($fingerprint->toArray()['state_sha256'], $fingerprint->stateSha256());
        self::assertSame('high', $assessedStage->toArray()['risk']['level']);
        self::assertContains('[fixture-1-to-2] Review the high-impact framework change.', $assessedStage->toArray()['recommended_actions']);
        self::assertContains('remediation-evidence', $assessedStage->evidenceReferences());
        self::assertSame([$stage], $resolution->stages());
        self::assertSame([$registryEntry], $resolution->blockerRegistry());
        self::assertTrue($registryEntry->isBlocking());
        self::assertContains('blocker-evidence', $resolution->evidenceReferences());
        self::assertCount(1, $assessed->toArray()['stages'][0]['source_findings']);
        self::assertCount(1, $assessed->toArray()['stages'][0]['source_impact']);
        self::assertCount(1, $assessed->toArray()['source_impact']);
    }

    public function testLegacySourceAssessmentsExcludeAnotherFrameworkForTheSameNumericHop(): void
    {
        $stage = new StageAnalysis(
            $this->target(),
            StageAnalysis::EXECUTED,
            StagedResolution::FEASIBLE,
            [],
            null,
            null,
            null,
            null,
            []
        );
        $resolution = new StagedResolution(
            StagedResolution::EVALUATED,
            StagedResolution::FEASIBLE,
            'fixture',
            [$stage],
            []
        );
        $foreignFinding = new CompatibilityFinding(
            'other-framework',
            'high',
            'This finding belongs to another framework.',
            ['foreign-finding-evidence'],
            [['from_major' => 1, 'to_major' => 2]]
        );
        $foreignImpact = new SourceImpactFinding(
            null,
            'unknown',
            'framework_rule',
            'The source is referenced only by another framework.',
            'high',
            [new SourceUsage(
                'src/Foreign.php',
                'Other\\Framework\\Client',
                'instantiated_class',
                ['foreign-finding-evidence'],
                24
            )],
            ['foreign-finding-evidence']
        );

        $assessed = $resolution->withSourceAssessments([$foreignFinding], [$foreignImpact])->toArray();

        self::assertSame([], $assessed['stages'][0]['source_findings']);
        self::assertSame([], $assessed['stages'][0]['source_impact']);
        self::assertCount(1, $assessed['source_impact']);
    }

    public function testStagedModelConstructorsRejectInvalidState(): void
    {
        $target = $this->target();
        $fingerprint = $this->fingerprint();
        $scenario = new Scenario('fixture-attempt', $target->targets());
        $result = new ScenarioResult($scenario, 0, '', '', new ComposerLock([]));

        $this->assertInvalid(
            static fn (): FrameworkStagePlan => new FrameworkStagePlan('', [], FrameworkStagePlan::REASON_MISSING_TARGET),
            'A framework stage plan must name its provider.'
        );
        $this->assertInvalid(
            static fn (): FrameworkStagePlan => new FrameworkStagePlan('fixture', [new \stdClass()]), // @phpstan-ignore argument.type
            'Framework stage plans may contain only FrameworkStageTarget instances.'
        );
        $this->assertInvalid(
            fn (): FrameworkStagePlan => new FrameworkStagePlan('fixture', [$target], FrameworkStagePlan::REASON_MISSING_TARGET),
            'An available stage plan cannot also have an unavailable reason.'
        );
        $this->assertInvalid(
            static fn (): FrameworkStagePlan => new FrameworkStagePlan('fixture', []),
            'An empty stage plan must explain why staged targets are unavailable.'
        );
        $this->assertInvalid(
            static fn (): FrameworkStagePlan => new FrameworkStagePlan('fixture', [], 'invented'),
            'Unsupported stage-plan reason "invented".'
        );

        $this->assertInvalidTarget('Bad ID', 'fixture', 1, 2, '8.3.0', [], [], ['target-evidence']);
        $this->assertInvalidTarget('fixture-1-to-2', '', 1, 2, '8.3.0', [], [], ['target-evidence']);
        $this->assertInvalidTarget('fixture-1-to-2', 'fixture', 1, 2, '8.2.0', [], [], ['target-evidence']);
        $this->assertInvalidTarget('fixture-1-to-2', 'fixture', 1, 2, '8.3.0', [], [], []);
        $this->assertInvalidTarget('fixture-1-to-2', 'fixture', 1, 2, '8.3.0', [new \stdClass()], [], ['target-evidence']);
        $this->assertInvalidTarget(
            'fixture-1-to-2',
            'fixture',
            1,
            2,
            '8.3.0',
            [new UpgradeTarget('vendor/remediation', '^2.0')],
            [],
            ['target-evidence']
        );
        $this->assertInvalidTarget(
            'fixture-1-to-2',
            'fixture',
            1,
            2,
            '8.3.0',
            [
                new UpgradeTarget('vendor/remediation', '^2.0'),
                new UpgradeTarget('VENDOR/REMEDIATION', '^2.0'),
            ],
            ['vendor/remediation' => ['remediation-evidence']],
            ['target-evidence']
        );
        $this->assertInvalidTarget(
            'fixture-1-to-2',
            'fixture',
            1,
            2,
            '8.3.0',
            [],
            ['vendor/undeclared' => ['remediation-evidence']],
            ['target-evidence']
        );

        $this->assertInvalid(
            fn (): StageAttempt => new StageAttempt(0, 'target_only', [], $result, $fingerprint, null, [], []),
            'Stage attempt numbers start at one.'
        );
        $this->assertInvalid(
            fn (): StageAttempt => new StageAttempt(1, 'target_only', [new \stdClass()], $result, $fingerprint, null, [], []), // @phpstan-ignore argument.type
            'Stage root changes must be RootConstraintChange instances.'
        );

        $this->assertInvalidStage('invented', null, [], []);
        $this->assertInvalidStage(StageAnalysis::EXECUTED, 'invented', [], []);
        $this->assertInvalidStage(StageAnalysis::SKIPPED, StagedResolution::UNKNOWN, [], []);
        $this->assertInvalidStage(StageAnalysis::EXECUTED, null, [new \stdClass()], []);
        $this->assertInvalidStage(StageAnalysis::EXECUTED, null, [], [new \stdClass()]);

        $this->assertInvalid(
            static fn (): StagedResolution => new StagedResolution('invented', StagedResolution::UNKNOWN, null, [], []),
            'Unsupported staged execution state "invented".'
        );
        $this->assertInvalid(
            static fn (): StagedResolution => new StagedResolution(StagedResolution::EVALUATED, 'invented', null, [], []),
            'Unsupported staged resolution status "invented".'
        );
        $this->assertInvalid(
            static fn (): StagedResolution => new StagedResolution(StagedResolution::EVALUATED, StagedResolution::UNKNOWN, null, [new \stdClass()], []), // @phpstan-ignore argument.type
            'Staged resolution may contain only StageAnalysis instances.'
        );
        $this->assertInvalid(
            static fn (): StagedResolution => new StagedResolution(StagedResolution::EVALUATED, StagedResolution::UNKNOWN, null, [], [new \stdClass()]), // @phpstan-ignore argument.type
            'The staged blocker registry may contain only StageBlockerEntry instances.'
        );
    }

    /**
     * @param list<mixed> $remediations
     * @param array<string, list<string>> $remediationEvidence
     * @param list<string> $evidence
     */
    private function assertInvalidTarget(
        string $id,
        string $framework,
        int $from,
        int $to,
        string $analysisPhp,
        array $remediations,
        array $remediationEvidence,
        array $evidence
    ): void {
        $this->expectInvalidWithoutMessage(static fn (): FrameworkStageTarget => new FrameworkStageTarget(
            $id,
            $framework,
            $from,
            $to,
            new UpgradeTargetSet([new UpgradeTarget('vendor/framework', '^2.0')], '8.3.0'),
            $analysisPhp,
            $remediations,
            $remediationEvidence,
            $evidence
        ));
    }

    /**
     * @param list<mixed> $attempts
     * @param list<mixed> $changes
     */
    private function assertInvalidStage(string $execution, ?string $status, array $attempts, array $changes): void
    {
        $this->expectInvalidWithoutMessage(fn (): StageAnalysis => new StageAnalysis(
            $this->target(),
            $execution,
            $status,
            $attempts,
            null,
            null,
            null,
            null,
            $changes
        ));
    }

    private function target(): FrameworkStageTarget
    {
        return new FrameworkStageTarget(
            'fixture-1-to-2',
            'fixture',
            1,
            2,
            new UpgradeTargetSet([new UpgradeTarget('vendor/framework', '^2.0')], '8.3.0'),
            '8.3.0',
            [new UpgradeTarget('vendor/remediation', '^2.0')],
            ['vendor/remediation' => ['remediation-evidence']],
            ['target-evidence']
        );
    }

    private function fingerprint(): ProjectStateFingerprint
    {
        $path = dirname(__DIR__, 5) . '/tests/fixtures/project-isolation';
        $project = new ProjectState($path, new ComposerJson(['require' => ['vendor/framework' => '^1.0']]), new ComposerLock([]));
        $request = new UpgradeRequest($path, [new UpgradeTarget('vendor/framework', '^2.0')], '8.1', '8.3.0');
        $platform = TargetPlatform::fromRequest($request, $project, [], '8.3.0');

        return ProjectStateFingerprint::fromState($project, $platform, '8.3.0', ['network' => false]);
    }

    private function assertInvalid(callable $callback, string $message): void
    {
        try {
            $callback();
            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function expectInvalidWithoutMessage(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
