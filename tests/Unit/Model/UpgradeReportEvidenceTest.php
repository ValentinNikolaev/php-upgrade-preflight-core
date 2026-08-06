<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\RiskSummary;
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

        self::assertSame('source-1', $report->evidence[0]->id);
    }

    /** @param list<Blocker> $blockers @param list<Evidence> $evidence @param list<string> $uncertainties */
    private function report(array $blockers, array $evidence, array $uncertainties = []): UpgradeReport
    {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        return new UpgradeReport(
            $request,
            new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([])),
            [],
            new LockDiff([]),
            $blockers,
            [],
            [],
            new RiskSummary('low', []),
            new EffortEstimate([0, 0], 'high', [], []),
            $uncertainties,
            $evidence
        );
    }
}
