<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\PlanStage;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\RootConstraintChange;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class UpgradeReportEvidenceTest extends TestCase
{
    public function testItRejectsAFindingWithoutEvidenceReferences(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must reference at least one evidence item');

        $this->report([
            new Blocker('conflict', 'fixture/package', 'Conflict.', 'high', []),
        ], []);
    }

    public function testItRejectsAFindingThatReferencesMissingEvidence(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing-1');

        $this->report([
            new Blocker('conflict', 'fixture/package', 'Conflict.', 'high', ['missing-1']),
        ], []);
    }

    public function testItRejectsEvidenceNotReferencedByTheReport(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Orphaned evidence');

        $this->report([], [
            new Evidence('solver-1', Evidence::E1_SOLVER, 'Unreferenced result.'),
        ]);
    }

    public function testAnUncertaintyMayReferenceParseFailureEvidence(): void
    {
        $report = $this->report([], [
            new Evidence('source-1', Evidence::E3_PROJECT_SOURCE, 'Parse failure.'),
        ], ['Source could not be parsed (source-1).']);

        self::assertSame('source-1', $report->evidence()[0]->id());
        self::assertSame('skipped', $report->stagedResolution()->executionState());
    }

    public function testRootConstraintChangesAndPlanStagesParticipateInEvidenceValidation(): void
    {
        $evidence = [new Evidence('manifest-1', Evidence::E2_PACKAGE_METADATA, 'Compared root constraints.')];
        $report = $this->report(
            [],
            $evidence,
            [],
            [new RootConstraintChange('fixture/dependency', 'updated', '^1.0', '^2.0', 'Target changed.', ['manifest-1'])],
            [new PlanStage('constraints', 'Update constraints.', ['Edit composer.json.'], ['manifest-1'])]
        );

        self::assertSame(['manifest-1'], $report->rootConstraintChanges()[0]->evidence());
        self::assertSame(['manifest-1'], $report->planStages()[0]->evidence());
    }

    public function testAdvisoriesAloneDoNotMakeAnUnknownResolutionBlocked(): void
    {
        $evidence = [new Evidence('lock-metadata-1', Evidence::E2_PACKAGE_METADATA, 'Package is abandoned.')];
        $advisoryReport = $this->report([
            new Blocker('abandoned-package', 'vendor/legacy', 'Abandoned.', 'high', ['lock-metadata-1']),
        ], $evidence);
        $extensionAdvisoryReport = $this->report([
            new Blocker('extension-version-unknown', 'ext-fixture', 'Version unknown.', 'medium', ['solver-1']),
        ], [new Evidence('solver-1', Evidence::E1_SOLVER, 'Extension version could not be reproduced.')]);
        $blockedReport = $this->report([
            new Blocker('conflict', 'vendor/package', 'Blocked.', 'high', ['solver-1']),
        ], [new Evidence('solver-1', Evidence::E1_SOLVER, 'Resolution failed.')]);

        self::assertSame('unknown', $advisoryReport->resolutionStatus());
        self::assertSame('unknown', $extensionAdvisoryReport->resolutionStatus());
        self::assertSame('blocked', $blockedReport->resolutionStatus());
    }

    public function testItRejectsAFrameworkFindingWithoutAnAssessedHop(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must identify at least one applicable hop');

        $this->report(
            [],
            [new Evidence('framework-1', Evidence::E2_PACKAGE_METADATA, 'Framework metadata matched.')],
            [],
            [],
            [],
            [new CompatibilityFinding('fixture', 'medium', 'Review the framework.', ['framework-1'])]
        );
    }

    /**
     * @param list<Blocker> $blockers
     * @param list<Evidence> $evidence
     * @param list<string> $uncertainties
     * @param list<RootConstraintChange> $rootConstraintChanges
     * @param list<PlanStage> $planStages
     * @param list<CompatibilityFinding> $frameworkFindings
     */
    private function report(
        array $blockers,
        array $evidence,
        array $uncertainties = [],
        array $rootConstraintChanges = [],
        array $planStages = [],
        array $frameworkFindings = []
    ): UpgradeReport {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        return new UpgradeReport(
            $request,
            new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([])),
            [],
            new LockDiff([]),
            $blockers,
            [],
            $frameworkFindings,
            new RiskSummary('low', []),
            new EffortEstimate([0, 0], 'high', [], []),
            $uncertainties,
            $evidence,
            $rootConstraintChanges,
            $planStages
        );
    }
}
