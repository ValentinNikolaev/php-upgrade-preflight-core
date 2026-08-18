<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\PackageRef;
use PHPUnit\Framework\TestCase;

final class ComposerLockTest extends TestCase
{
    /**
     * The analyzed project is untrusted input, and a lockfile the tool cannot fully understand must
     * still yield a canonical report. Indexing already skips rows missing a name or version, so a
     * row whose name violates Composer's grammar is skipped the same way rather than aborting the
     * whole analysis with an exception escaping analyzeUpgrade().
     */
    public function testItSkipsLockEntriesWhoseNameIsNotAValidComposerPackageName(): void
    {
        $lock = new ComposerLock([
            'packages' => [
                ['name' => 'vendor/valid', 'version' => '1.0.0'],
                ['name' => 'not a package', 'version' => '2.0.0'],
                ['name' => 'vendor/second-valid', 'version' => '3.0.0'],
            ],
        ]);

        self::assertSame(
            ['vendor/valid', 'vendor/second-valid'],
            array_keys($lock->packages())
        );
        self::assertNull($lock->package('not a package'));
        self::assertInstanceOf(PackageRef::class, $lock->package('vendor/valid'));
    }

    public function testItSkipsInvalidNamesInTheDevelopmentSectionToo(): void
    {
        $lock = new ComposerLock([
            'packages' => [['name' => 'vendor/runtime', 'version' => '1.0.0']],
            'packages-dev' => [
                ['name' => '/leading-slash', 'version' => '1.0.0'],
                ['name' => 'vendor/dev-tool', 'version' => '4.0.0'],
            ],
        ]);

        self::assertSame(['vendor/runtime', 'vendor/dev-tool'], array_keys($lock->packages()));
    }

    /**
     * The lock document is the information expert for what each locked package requires. Callers
     * that need a package's `require` block read it from the indexed PackageRef rather than
     * re-walking the `packages`/`packages-dev` sections themselves.
     */
    public function testItCarriesEachPackagesOwnRequirementsOntoTheIndexedPackageRef(): void
    {
        $lock = new ComposerLock([
            'packages' => [[
                'name' => 'vendor/runtime',
                'version' => '1.0.0',
                'require' => ['php' => '^8.0', 'illuminate/support' => '^9.0'],
            ]],
            'packages-dev' => [[
                'name' => 'vendor/dev-tool',
                'version' => '2.0.0',
                'require' => ['illuminate/support' => '^8.0'],
            ]],
        ]);

        $runtime = $lock->package('vendor/runtime');
        $devTool = $lock->package('vendor/dev-tool');
        self::assertInstanceOf(PackageRef::class, $runtime);
        self::assertInstanceOf(PackageRef::class, $devTool);
        self::assertSame(['php' => '^8.0', 'illuminate/support' => '^9.0'], $runtime->requirements());
        self::assertSame(['illuminate/support' => '^8.0'], $devTool->requirements());
    }

    public function testAMissingOrMalformedRequireBlockYieldsNoRequirements(): void
    {
        $lock = new ComposerLock([
            'packages' => [
                ['name' => 'vendor/none', 'version' => '1.0.0'],
                ['name' => 'vendor/malformed', 'version' => '1.0.0', 'require' => 'php ^8.0'],
            ],
        ]);

        $none = $lock->package('vendor/none');
        $malformed = $lock->package('vendor/malformed');
        self::assertInstanceOf(PackageRef::class, $none);
        self::assertInstanceOf(PackageRef::class, $malformed);
        self::assertSame([], $none->requirements());
        self::assertSame([], $malformed->requirements());
    }

    /**
     * Skipping an unusable lock entry keeps the analysis running, but silently dropping it would
     * under-report locked packages, package changes and autoload ownership with no visible reason.
     * The omission is published as uncertainty instead.
     */
    public function testSkippedLockEntriesArePublishedAsUncertaintyRatherThanDroppedSilently(): void
    {
        $lock = new ComposerLock([
            'packages' => [
                ['name' => 'vendor/valid', 'version' => '1.0.0'],
                ['name' => 'not a package', 'version' => '2.0.0'],
            ],
        ]);

        $uncertainties = $lock->unusablePackageUncertainties();
        self::assertCount(1, $uncertainties);
        self::assertStringContainsString('not a package', $uncertainties[0]);
        self::assertStringContainsString('may be incomplete', $uncertainties[0]);
    }

    public function testAFullyReadableLockPublishesNoUnusablePackageUncertainty(): void
    {
        $lock = new ComposerLock(['packages' => [['name' => 'vendor/valid', 'version' => '1.0.0']]]);

        self::assertSame([], $lock->unusablePackageUncertainties());
    }

    /** A pathological lockfile must not be able to inflate the report with unbounded prose. */
    public function testTheUnusablePackageUncertaintyIsBounded(): void
    {
        $packages = [];
        for ($i = 0; $i < 25; $i++) {
            $packages[] = ['name' => 'bad name ' . $i, 'version' => '1.0.0'];
        }
        $lock = new ComposerLock(['packages' => $packages]);

        $uncertainties = $lock->unusablePackageUncertainties();
        self::assertCount(1, $uncertainties);
        self::assertStringContainsString('(and 15 more)', $uncertainties[0]);
    }

    /**
     * A malformed name appearing in both lock sections is one unusable package, not two. Counting it
     * once per section printed it twice and overstated the bounded remainder.
     */
    public function testAnUnusableNameRepeatedInBothLockSectionsIsReportedOnce(): void
    {
        $lock = new ComposerLock([
            'packages' => [['name' => 'not a package', 'version' => '1.0.0']],
            'packages-dev' => [['name' => 'not a package', 'version' => '1.0.0']],
        ]);

        $uncertainties = $lock->unusablePackageUncertainties();
        self::assertCount(1, $uncertainties);
        self::assertSame(1, substr_count($uncertainties[0], 'not a package'));
        self::assertStringNotContainsString('more)', $uncertainties[0]);
    }

    public function testTheBoundedRemainderCountsDistinctUnusableNamesOnly(): void
    {
        $packages = [];
        for ($i = 0; $i < 12; $i++) {
            $packages[] = ['name' => 'bad name ' . $i, 'version' => '1.0.0'];
        }
        $lock = new ComposerLock(['packages' => $packages, 'packages-dev' => $packages]);

        self::assertStringContainsString('(and 2 more)', $lock->unusablePackageUncertainties()[0]);
    }

    /**
     * Composer always writes a version, so an entry without one is untrusted input that cannot be
     * indexed. Dropping it silently removed the package from locked packages, package changes, and
     * the framework rules that read its own `require` block, with nothing published to say so.
     */
    public function testAnEntryWithNoReadableVersionIsPublishedAsUncertaintyRatherThanDroppedSilently(): void
    {
        $lock = new ComposerLock([
            'packages' => [
                ['name' => 'vendor/valid', 'version' => '1.0.0'],
                ['name' => 'vendor/versionless', 'require' => ['illuminate/support' => '^7.0']],
                ['name' => 'vendor/array-version', 'version' => ['1.0.0']],
            ],
        ]);

        self::assertSame(['vendor/valid'], array_keys($lock->packages()));
        $uncertainties = $lock->unusablePackageUncertainties();
        self::assertCount(1, $uncertainties);
        self::assertStringContainsString('vendor/versionless', $uncertainties[0]);
        self::assertStringContainsString('vendor/array-version', $uncertainties[0]);
        self::assertStringContainsString('no readable version', $uncertainties[0]);
    }

    public function testScalarNonStringAndBlankVersionsAreNotCoercedIntoPackageVersions(): void
    {
        $lock = new ComposerLock([
            'packages' => [
                ['name' => 'vendor/valid', 'version' => '1.0.0'],
                ['name' => 'vendor/true-version', 'version' => true],
                ['name' => 'vendor/false-version', 'version' => false],
                ['name' => 'vendor/integer-version', 'version' => 42],
                ['name' => 'vendor/float-version', 'version' => 1.5],
                ['name' => 'vendor/empty-version', 'version' => ''],
                ['name' => 'vendor/blank-version', 'version' => '   '],
            ],
        ]);

        self::assertSame(['vendor/valid'], array_keys($lock->packages()));
        $uncertainties = $lock->unusablePackageUncertainties();
        self::assertCount(1, $uncertainties);
        foreach (['true', 'false', 'integer', 'float', 'empty', 'blank'] as $name) {
            self::assertStringContainsString('vendor/' . $name . '-version', $uncertainties[0]);
        }
    }

    /** The two skip reasons have different consequences, so they are published as separate sentences. */
    public function testInvalidNamesAndMissingVersionsArePublishedAsSeparateUncertainties(): void
    {
        $lock = new ComposerLock([
            'packages' => [
                ['name' => 'not a package', 'version' => '1.0.0'],
                ['name' => 'vendor/versionless'],
            ],
        ]);

        $uncertainties = $lock->unusablePackageUncertainties();
        self::assertCount(2, $uncertainties);
        self::assertStringContainsString('are not valid Composer package names', $uncertainties[0]);
        self::assertStringContainsString('carry no readable version', $uncertainties[1]);
    }

    /**
     * A candidate lock a scenario produced is discarded with its workspace, so its omissions are
     * worded for that lockfile rather than reading as if the project's own lock were unreadable.
     */
    public function testCandidateLockOmissionsAreWordedForTheScenarioLockfile(): void
    {
        $lock = new ComposerLock(['packages' => [['name' => 'not a package', 'version' => '1.0.0']]]);

        $uncertainties = $lock->unusableCandidatePackageUncertainties();
        self::assertCount(1, $uncertainties);
        self::assertStringStartsWith('Composer candidate lock entries were skipped', $uncertainties[0]);
        self::assertStringContainsString('not a package', $uncertainties[0]);
        self::assertNotSame($lock->unusablePackageUncertainties(), $uncertainties);
    }

    public function testAFullyReadableCandidateLockPublishesNoUncertainty(): void
    {
        $lock = new ComposerLock(['packages' => [['name' => 'vendor/valid', 'version' => '1.0.0']]]);

        self::assertSame([], $lock->unusableCandidatePackageUncertainties());
    }

    public function testValidNamesStillIndexNormallyIncludingDirectClassification(): void
    {
        $lock = new ComposerLock(
            ['packages' => [['name' => 'Vendor/Mixed-Case', 'version' => '1.2.3']]],
            ['vendor/mixed-case']
        );

        $package = $lock->package('vendor/mixed-case');
        self::assertInstanceOf(PackageRef::class, $package);
        self::assertSame('vendor/mixed-case', $package->name());
        self::assertTrue($package->isDirect());
    }
}
