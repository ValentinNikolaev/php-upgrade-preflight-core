<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Framework\FrameworkDetection;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CandidateLockEvidence;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\PackageRef;
use PhpUpgradePreflight\Core\Model\PlanStage;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\ReportSections;
use PhpUpgradePreflight\Core\Model\ReportMetadata;
use PhpUpgradePreflight\Core\Model\RootConstraintChange;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\TestGuidance;
use PhpUpgradePreflight\Core\Model\TargetPlatformPackage;
use PhpUpgradePreflight\Core\Model\TargetPlatformProfile;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class ImmutableDtoTest extends TestCase
{
    /**
     * @dataProvider dtoClassProvider
     * @param class-string $class
     */
    public function testDtoStateIsNotPubliclyMutable(string $class): void
    {
        self::assertSame([], (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC));
    }

    /** @return list<array{class-string}> */
    public function dtoClassProvider(): array
    {
        return [
            [Blocker::class],
            [CandidateLockEvidence::class],
            [CompatibilityFinding::class],
            [ComposerDiagnostic::class],
            [ComposerJson::class],
            [ComposerLock::class],
            [EffortEstimate::class],
            [Evidence::class],
            [FrameworkDetection::class],
            [LockDiff::class],
            [PackageChange::class],
            [PackageRef::class],
            [PlanStage::class],
            [ProjectState::class],
            [ReportSections::class],
            [ReportMetadata::class],
            [RootConstraintChange::class],
            [RiskSummary::class],
            [Scenario::class],
            [ScenarioResult::class],
            [SourceUsage::class],
            [TestGuidance::class],
            [TargetPlatformPackage::class],
            [TargetPlatformProfile::class],
            [UpgradeReport::class],
            [UpgradeRequest::class],
            [UpgradeTarget::class],
            [UpgradeTargetSet::class],
        ];
    }
}
