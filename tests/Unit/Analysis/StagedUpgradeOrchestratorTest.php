<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\StageBlockerRegistry;
use PhpUpgradePreflight\Core\Analysis\StagedAnalysisPolicy;
use PhpUpgradePreflight\Core\Analysis\StagedUpgradeOrchestrator;
use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Framework\FrameworkDetection;
use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Framework\FrameworkStageTargetProvider;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkStagePlan;
use PhpUpgradePreflight\Core\Model\FrameworkStageTarget;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\StagedResolution;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class StagedUpgradeOrchestratorTest extends TestCase
{
    public function testOldStyleAdapterWithoutStageProviderRemainsCompatible(): void
    {
        [$project, $request, $platform] = $this->context();
        $oldStyleAdapter = new class () implements FrameworkIntegration {
            public function name(): string
            {
                return 'old-style-adapter';
            }

            public function detect(ProjectState $project): FrameworkDetection
            {
                return new FrameworkDetection($this->name(), true, '1.0.0');
            }

            public function rules(): iterable
            {
                return [];
            }

            public function defaultSourcePaths(ProjectState $project): array
            {
                return [];
            }
        };

        $resolution = $this->orchestrator()->analyze(
            [$oldStyleAdapter],
            $project,
            $request,
            $platform,
            new EvidenceLedger()
        );

        self::assertSame(StagedResolution::SKIPPED, $resolution->executionState());
        self::assertSame(StagedResolution::UNKNOWN, $resolution->status());
        self::assertSame('stage_target_provider_unavailable', $resolution->toArray()['stop_reason']);
    }

    public function testItSkipsDeterministicallyWhenSeveralProvidersAreActive(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $providers = [
            $this->provider('zeta', new FrameworkStagePlan('zeta', [], FrameworkStagePlan::REASON_MISSING_TARGET)),
            $this->provider('alpha', new FrameworkStagePlan('alpha', [], FrameworkStagePlan::REASON_MISSING_TARGET)),
        ];

        $resolution = $this->orchestrator()->analyze($providers, $project, $request, $platform, $ledger);

        self::assertSame(StagedResolution::SKIPPED, $resolution->executionState());
        self::assertSame('multiple_stage_target_providers', $resolution->toArray()['stop_reason']);
        self::assertSame(['alpha', 'zeta'], $ledger->all()[0]->context()['providers']);
        $ledger->validateReferences($resolution->evidenceReferences());
    }

    public function testItRejectsANonContiguousPlanWithoutRunningComposerOrOrphaningEvidence(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $firstEvidence = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'First stage target.')->id();
        $secondEvidence = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Second stage target.')->id();
        $plan = new FrameworkStagePlan('fixture', [
            $this->stage(0, 1, $firstEvidence),
            $this->stage(2, 3, $secondEvidence),
        ], null, [$firstEvidence, $secondEvidence]);

        $resolution = $this->orchestrator()->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame(StagedResolution::SKIPPED, $resolution->executionState());
        self::assertSame('invalid_stage_plan', $resolution->toArray()['stop_reason']);
        $ledger->validateReferences($resolution->evidenceReferences());
    }

    public function testItRejectsProviderIdentityMismatchesBeforeComposerRuns(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Mismatched provider stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);

        $resolution = $this->orchestrator()->analyze(
            [$this->provider('different-provider', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame(StagedResolution::SKIPPED, $resolution->executionState());
        self::assertSame('invalid_stage_plan', $resolution->toArray()['stop_reason']);
        self::assertStringContainsString(
            'provider_identity_mismatch',
            $ledger->all()[count($ledger->all()) - 1]->context()['reason']
        );
    }

    public function testItRejectsProviderIdentityMismatchesForUnavailablePlans(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Unavailable mismatched plan.')->id();
        $plan = new FrameworkStagePlan(
            'fixture',
            [],
            FrameworkStagePlan::REASON_GUIDANCE_GAP,
            [$reference]
        );

        $resolution = $this->orchestrator()->analyze(
            [$this->provider('different-provider', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame(StagedResolution::SKIPPED, $resolution->executionState());
        self::assertSame('invalid_stage_plan', $resolution->toArray()['stop_reason']);
        self::assertSame(
            'provider_identity_mismatch',
            $ledger->all()[count($ledger->all()) - 1]->context()['reason']
        );
        $ledger->validateReferences($resolution->evidenceReferences());
    }

    public function testItRejectsProviderFailuresBeforeComposerRunsAndRetainsTheirEvidence(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $plan = new FrameworkStagePlan('fixture', [], FrameworkStagePlan::REASON_MISSING_TARGET);
        $provider = $this->provider('fixture', $plan, static function (EvidenceLedger $evidence): void {
            $evidence->add('provider-output', Evidence::E2_PACKAGE_METADATA, 'Provider output started.');
            throw new \RuntimeException('Fixture provider failure.');
        });

        $resolution = $this->orchestrator()->analyze([$provider], $project, $request, $platform, $ledger);

        self::assertSame(StagedResolution::SKIPPED, $resolution->executionState());
        self::assertSame('invalid_stage_plan', $resolution->toArray()['stop_reason']);
        self::assertSame('provider_failure', $ledger->all()[1]->context()['reason']);
        $ledger->validateReferences($resolution->evidenceReferences());
    }

    public function testItRejectsMissingProviderEvidenceBeforeComposerRuns(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $plan = new FrameworkStagePlan(
            'fixture',
            [],
            FrameworkStagePlan::REASON_MISSING_TARGET,
            ['missing-evidence']
        );

        $resolution = $this->orchestrator()->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame('invalid_stage_plan', $resolution->toArray()['stop_reason']);
        self::assertSame('missing_evidence_reference', $ledger->all()[0]->context()['reason']);
        self::assertNotContains('missing-evidence', $resolution->evidenceReferences());
        $ledger->validateReferences($resolution->evidenceReferences());
    }

    public function testItRejectsUnreferencedEvidenceCreatedByAProvider(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Declared stage evidence.')->id();
        $plan = new FrameworkStagePlan(
            'fixture',
            [],
            FrameworkStagePlan::REASON_MISSING_TARGET,
            [$reference]
        );
        $provider = $this->provider('fixture', $plan, static function (EvidenceLedger $evidence): void {
            $evidence->add('provider-output', Evidence::E2_PACKAGE_METADATA, 'Undeclared provider evidence.');
        });

        $resolution = $this->orchestrator()->analyze([$provider], $project, $request, $platform, $ledger);

        self::assertSame('invalid_stage_plan', $resolution->toArray()['stop_reason']);
        self::assertSame('unreferenced_provider_evidence', $ledger->all()[2]->context()['reason']);
        $ledger->validateReferences($resolution->evidenceReferences());
    }

    public function testItRejectsStageFrameworkMismatchesBeforeComposerRuns(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Mismatched stage framework.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference, 'other')], null, [$reference]);

        $resolution = $this->orchestrator()->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame('invalid_stage_plan', $resolution->toArray()['stop_reason']);
        self::assertSame('stage_framework_mismatch', $ledger->all()[1]->context()['reason']);
        $ledger->validateReferences($resolution->evidenceReferences());
    }

    public function testItEnforcesTheHopBudgetBeforeRunningComposerAndRetainsPlanEvidence(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $stages = [];
        $references = [];
        for ($from = 0; $from <= StagedAnalysisPolicy::MAX_HOPS; ++$from) {
            $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Budgeted stage target.')->id();
            $references[] = $reference;
            $stages[] = $this->stage($from, $from + 1, $reference);
        }
        $plan = new FrameworkStagePlan('fixture', $stages, null, $references);

        $resolution = $this->orchestrator()->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame(StagedResolution::SKIPPED, $resolution->executionState());
        self::assertSame('hop_budget_exceeded', $resolution->toArray()['stop_reason']);
        $ledger->validateReferences($resolution->evidenceReferences());
    }

    public function testItRejectsAPlanWhoseWorstCaseDiagnosticsExceedTheProcessBudget(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $stageEvidence = $ledger->add(
            'stage-target',
            Evidence::E2_PACKAGE_METADATA,
            'Process-budget stage target.'
        )->id();
        $remediations = [];
        $remediationEvidence = [];
        for ($index = 0; $index < StagedAnalysisPolicy::MAX_COMPOSER_PROCESSES; ++$index) {
            $package = sprintf('vendor/remediation-%d', $index);
            $reference = $ledger->add(
                'stage-remediation',
                Evidence::E2_PACKAGE_METADATA,
                'Process-budget remediation target.'
            )->id();
            $remediations[] = new UpgradeTarget($package, '^2.0');
            $remediationEvidence[$package] = [$reference];
        }
        $stage = new FrameworkStageTarget(
            'fixture-0-to-1',
            'fixture',
            0,
            1,
            new UpgradeTargetSet([new UpgradeTarget('vendor/framework', '^1.0')], '8.3.0'),
            '8.3.0',
            $remediations,
            $remediationEvidence,
            [$stageEvidence]
        );
        $planEvidence = array_merge([$stageEvidence], ...array_values($remediationEvidence));
        $plan = new FrameworkStagePlan('fixture', [$stage], null, $planEvidence);

        $resolution = $this->orchestrator()->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame(StagedResolution::SKIPPED, $resolution->executionState());
        self::assertSame(StagedResolution::UNKNOWN, $resolution->status());
        self::assertSame('process_budget_exceeded', $resolution->toArray()['stop_reason']);
        self::assertGreaterThan(
            StagedAnalysisPolicy::MAX_COMPOSER_PROCESSES,
            $ledger->all()[count($ledger->all()) - 1]->context()['projected_processes']
        );
        $ledger->validateReferences($resolution->evidenceReferences());
    }

    public function testStageTimeoutStopsAsUnknownAfterASolverFailure(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Timed stage target.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);

        $resolution = $this->solverFailureOrchestrator(
            ['ext-timeout'],
            [(float) StagedAnalysisPolicy::STAGE_TIMEOUT_SECONDS]
        )->analyze([$this->provider('fixture', $plan)], $project, $request, $platform, $ledger);
        $canonical = $resolution->toArray();

        self::assertSame(StagedResolution::UNKNOWN, $resolution->status());
        self::assertSame('stage_timeout', $canonical['stop_reason']);
        self::assertSame(StagedResolution::UNKNOWN, $canonical['stages'][0]['resolution_status']);
        self::assertSame('stage_timeout', $canonical['stages'][0]['stop_reason']);
        self::assertCount(1, $canonical['stages'][0]['attempts']);
    }

    public function testAnAttemptThatExceedsTheStageBudgetStopsAsUnknown(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Remaining-budget stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);

        $resolution = $this->solverFailureOrchestrator(
            ['ext-first', 'ext-must-not-run'],
            [1600.0, 0.1]
        )->analyze([$this->provider('fixture', $plan)], $project, $request, $platform, $ledger);
        $canonical = $resolution->toArray();

        self::assertSame(StagedResolution::UNKNOWN, $resolution->status());
        self::assertSame('stage_timeout', $canonical['stop_reason']);
        self::assertCount(1, $canonical['stages'][0]['attempts']);
    }

    public function testSlowDiagnosticsExhaustTheStageBudgetBeforeAnotherAttemptStarts(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Slow diagnostic stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);
        $primaryProcesses = 0;

        $resolution = $this->slowDiagnosticOrchestrator('stage', $primaryProcesses)->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );
        $canonical = $resolution->toArray();

        self::assertSame(StagedResolution::UNKNOWN, $resolution->status());
        self::assertSame('stage_timeout', $canonical['stop_reason']);
        self::assertSame(1, $primaryProcesses);
        self::assertCount(1, $canonical['stages'][0]['attempts']);
        self::assertSame(700000, $canonical['stages'][0]['attempts'][0]['scenario']['duration_ms']);
    }

    public function testSlowDiagnosticsExhaustTheAggregateBudgetBeforeAnotherAttemptStarts(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $first = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Slow first stage.')->id();
        $second = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Slow second stage.')->id();
        $plan = new FrameworkStagePlan(
            'fixture',
            [$this->stage(0, 1, $first), $this->stage(1, 2, $second)],
            null,
            [$first, $second]
        );
        $primaryProcesses = 0;

        $resolution = $this->slowDiagnosticOrchestrator('aggregate', $primaryProcesses)->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );
        $canonical = $resolution->toArray();

        self::assertSame(StagedResolution::UNKNOWN, $resolution->status());
        self::assertSame('aggregate_timeout', $canonical['stop_reason']);
        self::assertSame(3, $primaryProcesses);
        self::assertCount(2, $canonical['stages'][0]['attempts']);
        self::assertCount(1, $canonical['stages'][1]['attempts']);
        self::assertSame(700000, $canonical['stages'][1]['attempts'][0]['scenario']['duration_ms']);
    }

    public function testItCapsConfiguredScenarioTimeoutsForStagedExecutionAndReportsTheEffectivePolicy(): void
    {
        [$project, $request] = $this->context();
        $request = $request->withComposerExecution(new ComposerExecutionConfiguration(
            'composer',
            ComposerExecutionConfiguration::DEFAULT_EXPECTED_VERSION,
            3600
        ));
        $platform = TargetPlatform::fromRequest($request, $project, [], '8.3.0');
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Capped timeout stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);
        $timeouts = [];
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $_command, string $workspace, array $_environment, int $timeout) use (&$timeouts): array {
                $timeouts[] = $timeout;
                file_put_contents($workspace . '/composer.lock', json_encode([
                    'packages' => [['name' => 'vendor/framework', 'version' => '1.0.0']],
                    'packages-dev' => [],
                ], JSON_THROW_ON_ERROR));

                return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
            },
            static fn (): string => '2.8.12'
        );

        $resolution = (new StagedUpgradeOrchestrator($runner))->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );
        $stage = $resolution->toArray()['stages'][0];

        self::assertSame([StagedAnalysisPolicy::SCENARIO_TIMEOUT_SECONDS], $timeouts);
        self::assertSame(3600, $request->composerExecution()->scenarioTimeoutSeconds());
        self::assertSame(
            StagedAnalysisPolicy::SCENARIO_TIMEOUT_SECONDS,
            $stage['composer_execution']['configuration']['scenario_timeout_seconds']
        );
        self::assertSame(
            $stage['input_state']['execution_policy_sha256'],
            $stage['composer_execution']['effective_sha256']
        );
    }

    public function testItUsesTheContractedLockedRemediationOrderWithoutRootCandidates(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Locked remediation stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);

        $resolution = $this->solverFailureOrchestrator(
            ['ext-first', 'ext-second'],
            [0.1, 0.1]
        )->analyze([$this->provider('fixture', $plan)], $project, $request, $platform, $ledger);

        self::assertSame(
            ['target_only', 'locked_package_remediation'],
            array_column($resolution->toArray()['stages'][0]['attempts'], 'strategy')
        );
    }

    public function testReappearingBlockerKeepsOneRegistryEntryAndItsCompleteHistory(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Lifecycle stage.')->id();
        $firstRemediation = $ledger->add('stage-remediation', Evidence::E2_PACKAGE_METADATA, 'First remediation.')->id();
        $secondRemediation = $ledger->add('stage-remediation', Evidence::E2_PACKAGE_METADATA, 'Second remediation.')->id();
        $stage = new FrameworkStageTarget(
            'fixture-0-to-1',
            'fixture',
            0,
            1,
            new UpgradeTargetSet([new UpgradeTarget('vendor/framework', '^1.0')], '8.3.0'),
            '8.3.0',
            [
                new UpgradeTarget('vendor/first-remediation', '^2.0'),
                new UpgradeTarget('vendor/second-remediation', '^2.0'),
            ],
            [
                'vendor/first-remediation' => [$firstRemediation],
                'vendor/second-remediation' => [$secondRemediation],
            ],
            [$reference]
        );
        $plan = new FrameworkStagePlan('fixture', [$stage], null, [$reference]);

        $resolution = $this->solverFailureOrchestrator(
            ['ext-repeat', 'ext-intermediate', 'ext-repeat'],
            [0.1, 0.1, 0.1]
        )->analyze([$this->provider('fixture', $plan)], $project, $request, $platform, $ledger);
        $registry = $resolution->toArray()['blocker_registry'];
        $bySubject = [];
        foreach ($registry as $entry) {
            $bySubject[$entry['subject']] = $entry;
        }

        self::assertCount(2, $registry);
        self::assertCount(2, array_unique(array_column($registry, 'id')));
        self::assertSame(
            ['detected', 'resolved', 'detected'],
            array_column($bySubject['ext-repeat']['lifecycle_history'], 'status')
        );
        self::assertSame([1, 3], array_column($bySubject['ext-repeat']['observations'], 'attempt'));
        self::assertSame('detected', $bySubject['ext-repeat']['lifecycle']);
        self::assertSame('resolved', $bySubject['ext-intermediate']['lifecycle']);
    }

    public function testTimeoutStopsAsUnknownAndSkipsLaterStages(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $firstEvidence = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Timed stage target.')->id();
        $secondEvidence = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Later stage target.')->id();
        $plan = new FrameworkStagePlan('fixture', [
            $this->stage(0, 1, $firstEvidence),
            $this->stage(1, 2, $secondEvidence),
        ], null, [$firstEvidence, $secondEvidence]);

        $resolution = $this->timeoutOrchestrator()->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );
        $canonical = $resolution->toArray();

        self::assertSame(StagedResolution::UNKNOWN, $resolution->status());
        self::assertSame('timeout', $canonical['stop_reason']);
        self::assertSame(StagedResolution::UNKNOWN, $canonical['stages'][0]['resolution_status']);
        self::assertSame('timeout', $canonical['stages'][0]['stop_reason']);
        self::assertSame('skipped', $canonical['stages'][1]['execution_state']);
        self::assertSame('previous_stage_unknown', $canonical['stages'][1]['stop_reason']);
        self::assertNotSame([], $canonical['stages'][1]['evidence']);
        self::assertSame('8.3.0', $canonical['stages'][1]['platform']['analysis_php']);
    }

    public function testOperationalFailureStopsAsUnknownAndSkipsLaterStages(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $firstEvidence = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Operational stage target.')->id();
        $secondEvidence = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Later stage target.')->id();
        $plan = new FrameworkStagePlan('fixture', [
            $this->stage(0, 1, $firstEvidence),
            $this->stage(1, 2, $secondEvidence),
        ], null, [$firstEvidence, $secondEvidence]);

        $resolution = $this->operationalFailureOrchestrator()->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );
        $canonical = $resolution->toArray();

        self::assertSame(StagedResolution::UNKNOWN, $resolution->status());
        self::assertSame('operational_failure', $canonical['stop_reason']);
        self::assertSame(StagedResolution::UNKNOWN, $canonical['stages'][0]['resolution_status']);
        self::assertSame('process_failure', $canonical['stages'][0]['attempts'][0]['scenario']['outcome']);
        self::assertSame('skipped', $canonical['stages'][1]['execution_state']);
        self::assertSame('previous_stage_unknown', $canonical['stages'][1]['stop_reason']);
    }

    public function testSuccessfulStagesCarryOnlyTheSelectedCandidateStateForward(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $firstEvidence = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'First successful stage.')->id();
        $secondEvidence = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Second successful stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [
            $this->stage(0, 1, $firstEvidence),
            $this->stage(1, 2, $secondEvidence),
        ], null, [$firstEvidence, $secondEvidence]);

        $resolution = $this->successfulOrchestrator()->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );
        $canonical = $resolution->toArray();

        self::assertSame(StagedResolution::EVALUATED, $resolution->executionState());
        self::assertSame(StagedResolution::FEASIBLE_WITH_CHANGES, $resolution->status());
        self::assertNull($canonical['stop_reason']);
        self::assertSame([1, 1], array_column($canonical['stages'], 'selected_attempt'));
        self::assertSame(
            [StagedResolution::FEASIBLE_WITH_CHANGES, StagedResolution::FEASIBLE_WITH_CHANGES],
            array_column($canonical['stages'], 'resolution_status')
        );
        self::assertSame(
            $canonical['stages'][0]['output_state']['state_sha256'],
            $canonical['stages'][1]['predecessor_state']['state_sha256']
        );
        self::assertSame(['vendor/dependency', 'vendor/framework'], array_column(
            $canonical['stages'][0]['package_changes'],
            'name'
        ));
        self::assertTrue($canonical['stages'][0]['attempts'][0]['selected']);
        self::assertSame('8.3.0', $canonical['stages'][0]['platform']['analysis_php']);
        self::assertSame('partial', $canonical['stages'][0]['platform']['completeness']);
        self::assertSame(
            $canonical['stages'][0]['input_state']['platform_sha256'],
            $canonical['stages'][0]['platform']['effective_sha256']
        );
        self::assertSame(
            $canonical['stages'][0]['input_state']['execution_policy_sha256'],
            $canonical['stages'][0]['composer_execution']['effective_sha256']
        );
        self::assertGreaterThanOrEqual(0, $canonical['stages'][0]['duration_ms']);
        self::assertNotSame([], $canonical['stages'][0]['evidence']);
        self::assertSame([], $canonical['blocker_registry']);
        $ledger->validateReferences($resolution->evidenceReferences());
    }

    public function testSuccessfulStageWithoutALockDiffRemainsFeasible(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'No-diff stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static fn (): array => ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''],
            static fn (): string => '2.8.12'
        );

        $resolution = (new StagedUpgradeOrchestrator($runner))->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame(StagedResolution::FEASIBLE, $resolution->status());
        self::assertSame([], $resolution->toArray()['stages'][0]['package_changes']);
    }

    public function testConsecutiveBlockerObservationPersistsWithoutDuplicatingTheRegistry(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Persistent blocker stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);

        $resolution = $this->solverFailureOrchestrator(['ext-repeat', 'ext-repeat'], [0.1, 0.1])->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );
        $registry = $resolution->toArray()['blocker_registry'];

        self::assertCount(1, $registry);
        self::assertSame(['detected', 'persists'], array_column($registry[0]['lifecycle_history'], 'status'));
    }

    public function testChangedConstraintSupersedesTheEarlierBlockerIdentity(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Superseded blocker stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);
        $outputs = [
            "Your requirements could not be resolved to an installable set of packages.\nProblem 1\n- vendor/blocker 1.0.0 requires vendor/framework ^0.0 -> found vendor/framework[0.0.0] but it conflicts with your root composer.json require (^1.0).",
            "Your requirements could not be resolved to an installable set of packages.\nProblem 1\n- vendor/blocker 1.0.0 requires vendor/framework ^0.5 -> found vendor/framework[0.5.0] but it conflicts with your root composer.json require (^1.0).",
        ];

        $resolution = $this->rawSolverFailureOrchestrator($outputs)->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame(['superseded', 'detected'], array_column(
            $resolution->toArray()['blocker_registry'],
            'lifecycle'
        ));
    }

    public function testDuplicateStageIdentifiersAreRejectedBeforeComposerRuns(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Duplicate stage.')->id();
        $stage = $this->stage(0, 1, $reference);
        $plan = new FrameworkStagePlan('fixture', [$stage, $stage], null, [$reference]);

        $resolution = $this->orchestrator()->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame('invalid_stage_plan', $resolution->toArray()['stop_reason']);
    }

    public function testMatchingRootConstraintDoesNotProduceARecordedChange(): void
    {
        [$project, $request] = $this->context();
        $project = new ProjectState(
            $project->path(),
            new ComposerJson(['name' => 'fixture/project', 'require' => ['vendor/framework' => '^1.0']]),
            new ComposerLock(['packages' => []])
        );
        $platform = TargetPlatform::fromRequest($request, $project, [], '8.3.0');
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Matching root stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);

        $resolution = $this->solverFailureOrchestrator(
            ['ext-blocked', 'ext-still-blocked'],
            [0.1, 0.1]
        )->analyze(
            [$this->provider('fixture', $plan)],
            $project,
            $request,
            $platform,
            $ledger
        );

        self::assertSame([], $resolution->toArray()['stages'][0]['attempts'][0]['root_constraint_changes']);
    }

    public function testSuccessfulCandidateIsNotSelectedWhileItsBlockingEntryRemainsActive(): void
    {
        $registry = new StageBlockerRegistry();
        $registry->observe(
            'fixture-0-to-1',
            1,
            'fixture-attempt',
            [new Blocker(
                'transitive-package-conflict',
                'vendor/framework',
                'A transitive package blocks the target.',
                'high',
                ['solver-evidence'],
                '^1.0',
                'vendor/blocker',
                '1.0.0',
                '^0.0',
                ['vendor/blocker', 'vendor/framework'],
                ['Upgrade vendor/blocker.']
            )],
            'attempt-evidence',
            false
        );

        self::assertTrue($registry->hasActiveBlocking('fixture-0-to-1'));
        self::assertFalse($registry->hasActiveBlocking('fixture-1-to-2'));
    }

    public function testAnAttemptThatExceedsTheAggregateBudgetStopsAsUnknown(): void
    {
        [$project, $request, $platform] = $this->context();
        $ledger = new EvidenceLedger();
        $reference = $ledger->add('stage-target', Evidence::E2_PACKAGE_METADATA, 'Aggregate-budget stage.')->id();
        $plan = new FrameworkStagePlan('fixture', [$this->stage(0, 1, $reference)], null, [$reference]);

        $resolution = $this->solverFailureOrchestrator(
            ['ext-first', 'ext-must-not-run'],
            [(float) StagedAnalysisPolicy::AGGREGATE_TIMEOUT_SECONDS + 100.0, 0.1]
        )->analyze([$this->provider('fixture', $plan)], $project, $request, $platform, $ledger);
        $canonical = $resolution->toArray();

        self::assertSame(StagedResolution::UNKNOWN, $resolution->status());
        self::assertSame('aggregate_timeout', $canonical['stop_reason']);
        self::assertSame('aggregate_timeout', $canonical['stages'][0]['stop_reason']);
        self::assertCount(1, $canonical['stages'][0]['attempts']);
    }

    private function orchestrator(): StagedUpgradeOrchestrator
    {
        return new StagedUpgradeOrchestrator(new ComposerScenarioRunner(
            null,
            null,
            static function (): array {
                throw new \LogicException('Composer must not run for pre-execution stop conditions.');
            }
        ));
    }

    /**
     * @param list<string> $extensions
     * @param list<float> $durations
     */
    private function solverFailureOrchestrator(array $extensions, array $durations): StagedUpgradeOrchestrator
    {
        $outputs = array_map(static function (string $extension): string {
            return implode("\n", [
                'Your requirements could not be resolved to an installable set of packages.',
                'Problem 1',
                sprintf('- vendor/blocker 1.0.0 requires %s * -> it is missing from your system.', $extension),
            ]);
        }, $extensions);
        /** @var list<float> $clockValues */
        $clockValues = [];
        $current = 0.0;
        foreach ($durations as $duration) {
            $clockValues[] = $current;
            $current += $duration;
            $clockValues[] = $current;
        }

        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function () use (&$outputs): array {
                $output = array_shift($outputs);
                if (!is_string($output)) {
                    throw new \LogicException('No staged solver transcript remains.');
                }

                return ['exit_code' => 2, 'stdout' => '', 'stderr' => $output];
            },
            static fn (): string => '2.3.0',
            static function () use (&$clockValues): float {
                $value = array_shift($clockValues);
                if (!is_float($value)) {
                    throw new \LogicException('No staged solver clock value remains.');
                }

                return $value;
            }
        );

        return new StagedUpgradeOrchestrator($runner);
    }

    /** @param list<string> $outputs */
    private function rawSolverFailureOrchestrator(array $outputs): StagedUpgradeOrchestrator
    {
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function () use (&$outputs): array {
                $output = array_shift($outputs);
                if (!is_string($output)) {
                    throw new \LogicException('No raw staged solver transcript remains.');
                }

                return ['exit_code' => 2, 'stdout' => '', 'stderr' => $output];
            },
            static fn (): string => '2.3.0'
        );

        return new StagedUpgradeOrchestrator($runner);
    }

    private function slowDiagnosticOrchestrator(string $mode, int &$primaryProcesses): StagedUpgradeOrchestrator
    {
        $now = 0.0;
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $workspace) use (
                $mode,
                &$primaryProcesses,
                &$now
            ): array {
                if (in_array('prohibits', $command, true)) {
                    // The aggregate case must leave the first stage enough room for a
                    // second attempt, whose reservation covers its own diagnostics too.
                    $now += $mode === 'aggregate' && $primaryProcesses === 1 ? 250.0 : 300.0;

                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Synthetic slow diagnostic.'];
                }

                ++$primaryProcesses;
                if ($mode === 'aggregate' && $primaryProcesses === 2) {
                    $now += 299.0;
                    $contents = file_get_contents($workspace . '/composer.json');
                    if (!is_string($contents)
                        || preg_match('/"vendor\\/framework"\s*:\s*"\^(\d+)\.0"/', $contents, $matches) !== 1) {
                        throw new \RuntimeException('The slow diagnostic fixture has no staged framework target.');
                    }
                    file_put_contents($workspace . '/composer.lock', json_encode([
                        'content-hash' => 'slow-diagnostic-stage',
                        'packages' => [['name' => 'vendor/framework', 'version' => $matches[1] . '.0.0']],
                        'packages-dev' => [],
                    ], JSON_THROW_ON_ERROR));

                    return ['exit_code' => 0, 'stdout' => 'Resolved.', 'stderr' => ''];
                }

                $now += 100.0;

                return [
                    'exit_code' => 2,
                    'stdout' => '',
                    'stderr' => implode("\n", [
                        'Your requirements could not be resolved to an installable set of packages.',
                        '- vendor/blocker 1.0.0 requires ext-slow * -> it is missing from your system.',
                    ]),
                ];
            },
            static fn (): string => '2.8.12',
            static function () use (&$now): float {
                return $now;
            }
        );

        return new StagedUpgradeOrchestrator($runner);
    }

    private function timeoutOrchestrator(): StagedUpgradeOrchestrator
    {
        $process = new Process(['composer', 'update']);
        $process->setTimeout(StagedAnalysisPolicy::SCENARIO_TIMEOUT_SECONDS);
        $runner = new ComposerScenarioRunner(null, null, static function () use ($process): array {
            throw new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
        });

        return new StagedUpgradeOrchestrator($runner);
    }

    private function operationalFailureOrchestrator(): StagedUpgradeOrchestrator
    {
        $runner = new ComposerScenarioRunner(null, null, static fn (): array => [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'Transport failed before dependency resolution.',
        ]);

        return new StagedUpgradeOrchestrator($runner);
    }

    private function successfulOrchestrator(): StagedUpgradeOrchestrator
    {
        $runner = new ComposerScenarioRunner(
            null,
            null,
            static function (array $command, string $workspace): array {
                $contents = file_get_contents($workspace . '/composer.json');
                if (!is_string($contents)) {
                    throw new \RuntimeException('The staged manifest was not written.');
                }
                $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                $constraint = $manifest['require']['vendor/framework'] ?? null;
                if (!is_string($constraint) || preg_match('/\^(\d+)\.0/', $constraint, $matches) !== 1) {
                    throw new \RuntimeException('The staged framework constraint was not applied.');
                }
                $major = $matches[1];
                $lock = [
                    'content-hash' => hash('sha256', $contents),
                    'packages' => [
                        ['name' => 'vendor/dependency', 'version' => $major . '.0.0'],
                        ['name' => 'vendor/framework', 'version' => $major . '.0.0'],
                    ],
                    'packages-dev' => [],
                ];
                file_put_contents(
                    $workspace . '/composer.lock',
                    json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );

                return ['exit_code' => 0, 'stdout' => 'Lock file operations completed.', 'stderr' => ''];
            },
            static fn (): string => '2.8.12'
        );

        return new StagedUpgradeOrchestrator($runner);
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

    private function stage(int $from, int $to, string $evidence, string $framework = 'fixture'): FrameworkStageTarget
    {
        return new FrameworkStageTarget(
            sprintf('fixture-%d-to-%d', $from, $to),
            $framework,
            $from,
            $to,
            new UpgradeTargetSet([new UpgradeTarget('vendor/framework', '^' . $to . '.0')], '8.3.0'),
            '8.3.0',
            [],
            [],
            [$evidence]
        );
    }

    private function provider(
        string $name,
        FrameworkStagePlan $plan,
        ?callable $beforeReturn = null
    ): FrameworkIntegration {
        return new class ($name, $plan, $beforeReturn) implements FrameworkIntegration, FrameworkStageTargetProvider {
            private string $name;
            private FrameworkStagePlan $plan;
            private ?\Closure $beforeReturn;

            public function __construct(string $name, FrameworkStagePlan $plan, ?callable $beforeReturn)
            {
                $this->name = $name;
                $this->plan = $plan;
                $this->beforeReturn = $beforeReturn === null ? null : \Closure::fromCallable($beforeReturn);
            }

            public function name(): string
            {
                return $this->name;
            }

            public function detect(ProjectState $project): FrameworkDetection
            {
                return new FrameworkDetection($this->name, true, '1.0.0');
            }

            public function rules(): iterable
            {
                return [];
            }

            public function defaultSourcePaths(ProjectState $project): array
            {
                return [];
            }

            public function planStages(
                ProjectState $project,
                UpgradeRequest $request,
                EvidenceLedger $evidence
            ): FrameworkStagePlan {
                if ($this->beforeReturn !== null) {
                    ($this->beforeReturn)($evidence);
                }

                return $this->plan;
            }
        };
    }
}
