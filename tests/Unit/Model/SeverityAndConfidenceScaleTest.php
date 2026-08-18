<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\Confidence;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Severity;
use PhpUpgradePreflight\Core\Model\SourceImpactFinding;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PHPUnit\Framework\TestCase;

final class SeverityAndConfidenceScaleTest extends TestCase
{
    public function testBothScalesDeclareTheSameCanonicalStrings(): void
    {
        self::assertSame(['low', 'medium', 'high'], Severity::values());
        self::assertSame(['low', 'medium', 'high'], Confidence::values());
        self::assertTrue(Severity::isValid('medium'));
        self::assertFalse(Severity::isValid('warning'));
        self::assertTrue(Confidence::isValid('medium'));
        self::assertFalse(Confidence::isValid('certain'));
    }

    public function testEverySeverityFieldEnforcesTheSharedScale(): void
    {
        $this->assertEveryConstructorIsRejected([
            'framework finding' => [
                'Unsupported framework finding severity "warning".',
                static fn () => new CompatibilityFinding(
                    'fixture',
                    'warning',
                    'Framework migration guidance requires review.',
                    ['docs-1']
                ),
            ],
            'source impact' => [
                'Unsupported source-impact severity "critical".',
                static fn () => new SourceImpactFinding(
                    'vendor/package',
                    'exact',
                    'package_change',
                    'The owning package changes.',
                    'critical',
                    [new SourceUsage('src/Example.php', 'Vendor\\Package', 'namespace_import', ['source-1'])],
                    ['source-1']
                ),
            ],
            'risk summary' => [
                'Unsupported risk level "catastrophic".',
                static fn () => new RiskSummary('catastrophic', []),
            ],
        ]);
    }

    public function testEveryConfidenceFieldEnforcesTheSharedScale(): void
    {
        $this->assertEveryConstructorIsRejected([
            'evidence' => [
                'Unsupported evidence confidence "certain".',
                static fn () => new Evidence(
                    'solver-1',
                    Evidence::E1_SOLVER,
                    'Composer rejected the target.',
                    'certain'
                ),
            ],
            'evidence ledger' => [
                'Unsupported evidence confidence "certain".',
                static fn () => (new EvidenceLedger())->add(
                    'solver',
                    Evidence::E1_SOLVER,
                    'Composer rejected the target.',
                    'certain'
                ),
            ],
            'effort estimate' => [
                'Unsupported effort confidence "certain".',
                static fn () => new EffortEstimate([0, 0], 'certain', [], []),
            ],
        ]);
    }

    public function testTheScalesKeepEmittingPlainStrings(): void
    {
        $finding = new CompatibilityFinding('fixture', Severity::MEDIUM, 'Review the framework.', ['docs-1']);
        $evidence = new Evidence('docs-1', Evidence::E4_MAINTAINER_DOCUMENTATION, 'Guidance.', Confidence::MEDIUM);
        $impact = new SourceImpactFinding(
            'vendor/package',
            'exact',
            'package_change',
            'The owning package changes.',
            Severity::HIGH,
            [new SourceUsage('src/Example.php', 'Vendor\\Package', 'namespace_import', ['source-1'])],
            ['source-1']
        );

        self::assertSame('medium', $finding->toArray()['severity']);
        self::assertSame('medium', $evidence->toArray()['confidence']);
        self::assertSame('high', $impact->toArray()['severity']);
        self::assertSame('low', (new RiskSummary(Severity::LOW, []))->toArray()['level']);
        self::assertSame('low', (new EffortEstimate([0, 0], Confidence::LOW, [], []))->toArray()['confidence']);
    }

    public function testRiskDriversMustBeStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Risk drivers must be strings.');

        new RiskSummary('low', [42]); // @phpstan-ignore argument.type
    }

    /** @param array<string, array{string, \Closure(): mixed}> $cases */
    private function assertEveryConstructorIsRejected(array $cases): void
    {
        foreach ($cases as $label => [$expectedMessage, $construct]) {
            try {
                $construct();
                self::fail(sprintf('Expected the %s scale to be enforced.', $label));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($expectedMessage, $exception->getMessage());
            }
        }
    }
}
