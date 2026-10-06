<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\AbandonedPackageDetector;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PHPUnit\Framework\TestCase;

final class AbandonedPackageDetectorTest extends TestCase
{
    public function testItDetectsBooleanAndReplacementMetadataDeterministically(): void
    {
        $lock = new ComposerLock([
            'packages' => [
                ['name' => 'vendor/zeta', 'version' => '2.0.0', 'abandoned' => true],
                ['name' => 'vendor/active', 'version' => '1.0.0', 'abandoned' => false],
            ],
            'packages-dev' => [
                ['name' => 'Vendor/Alpha', 'version' => '1.4.0', 'abandoned' => 'Vendor/Replacement'],
            ],
        ], ['vendor/alpha']);
        $evidence = new EvidenceLedger();

        $blockers = (new AbandonedPackageDetector())->detect($lock, $evidence, [
            'vendor/alpha' => '^1.0',
        ]);

        self::assertSame(['vendor/alpha', 'vendor/zeta'], array_map(
            static fn (Blocker $blocker): string => $blocker->subject(),
            $blockers
        ));
        self::assertSame('abandoned-package', $blockers[0]->type());
        self::assertSame('^1.0', $blockers[0]->requestedConstraint());
        self::assertSame('1.4.0', $blockers[0]->lockedVersion());
        self::assertSame(['vendor/alpha'], $blockers[0]->dependencyPath());
        self::assertSame(['Replace `vendor/alpha` with `vendor/replacement`.'], $blockers[0]->options());
        self::assertNull($blockers[1]->requestedConstraint());
        self::assertSame(['Replace or remove `vendor/zeta`.'], $blockers[1]->options());
        self::assertSame([['lock-metadata-1'], ['lock-metadata-2']], array_map(
            static fn (Blocker $blocker): array => $blocker->evidence(),
            $blockers
        ));

        self::assertCount(2, $evidence->all());
        self::assertSame(Evidence::E2_PACKAGE_METADATA, $evidence->all()[0]->evidenceClass());
        self::assertSame([
            'package' => 'vendor/alpha',
            'locked_version' => '1.4.0',
            'direct' => true,
            'abandoned_alternative' => 'Vendor/Replacement',
            'abandoned_alternative_type' => 'package',
        ], $evidence->all()[0]->context());
        self::assertSame(false, $evidence->all()[1]->context()['direct']);
        $evidence->validateReferences(array_merge($blockers[0]->evidence(), $blockers[1]->evidence()));
    }

    public function testItPreservesUrlAlternativesExactly(): void
    {
        $alternative = 'https://Example.com/Org/Replacement?Ref=ABC';
        $lock = new ComposerLock([
            'packages' => [[
                'name' => 'vendor/legacy',
                'version' => '1.0.0',
                'abandoned' => $alternative,
            ]],
        ]);
        $evidence = new EvidenceLedger();

        $blockers = (new AbandonedPackageDetector())->detect($lock, $evidence);
        $package = $lock->package('vendor/legacy');

        self::assertNotNull($package);
        self::assertSame($alternative, $package->abandonedAlternative());
        self::assertSame('url', $package->abandonedAlternativeType());
        self::assertNull($package->replacementPackage());
        self::assertSame([
            sprintf('Review the recommended alternative for `vendor/legacy`: %s.', $alternative),
        ], $blockers[0]->options());
        self::assertSame($alternative, $evidence->all()[0]->context()['abandoned_alternative']);
        self::assertSame('url', $evidence->all()[0]->context()['abandoned_alternative_type']);
        self::assertSame($alternative, $package->toArray()['abandoned_alternative']);
    }

    public function testItIgnoresFalseEmptyAndUnsupportedAbandonedValues(): void
    {
        $lock = new ComposerLock([
            'packages' => [
                ['name' => 'vendor/false', 'version' => '1.0.0', 'abandoned' => false],
                ['name' => 'vendor/empty', 'version' => '1.0.0', 'abandoned' => '  '],
                ['name' => 'vendor/invalid', 'version' => '1.0.0', 'abandoned' => 1],
            ],
        ]);
        $evidence = new EvidenceLedger();

        self::assertSame([], (new AbandonedPackageDetector())->detect($lock, $evidence));
        self::assertSame([], $evidence->all());
    }
}
