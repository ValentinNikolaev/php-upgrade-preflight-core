<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\ExtensionAssumption;
use PhpUpgradePreflight\Core\Model\HostExtension;
use PhpUpgradePreflight\Core\Model\PlatformProvenance;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\TargetPlatformPackage;
use PhpUpgradePreflight\Core\Model\TargetPlatformProfile;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class TargetPlatformResolutionTest extends TestCase
{
    public function testCompleteProfileMergesRequestAndProjectWithClosedWorldPrecedence(): void
    {
        $profile = $this->profile('complete', [
            'php' => '8.3.4',
            'ext-json' => '8.3.0',
            'lib-icu' => '73.2',
            'php-zts' => false,
            'composer' => '2.8.12',
            'composer-plugin-api' => '2.6.0',
            'composer-runtime-api' => '2.2.2',
        ]);
        $request = new UpgradeRequest(
            $this->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            '8.3.4',
            [],
            [],
            'json',
            null,
            false,
            [
                ExtensionAssumption::fromPresenceInput('ext-json:8.3.0'),
                ExtensionAssumption::fromAbsenceInput('ext-intl'),
            ],
            $profile
        );
        $project = new ProjectState(
            $this->projectPath(),
            new ComposerJson(['config' => ['platform' => [
                'php' => '8.1.0',
                'ext-json' => '8.1.0',
                'ext-curl' => '8.1.0',
                'lib-icu' => '70.1',
            ]]]),
            new ComposerLock([])
        );

        $platform = TargetPlatform::fromRequest($request, $project, [new HostExtension('json', '8.4.0')], '8.4.0');
        $effective = [];
        foreach ($platform->platformPackages() as $package) {
            $effective[$package->name()] = $package->toArray();
        }

        self::assertSame(TargetPlatformPackage::PROVENANCE_REQUEST, $effective['php']['provenance']);
        self::assertSame(TargetPlatformPackage::PROVENANCE_REQUEST, $effective['ext-json']['provenance']);
        self::assertSame(TargetPlatformPackage::PROVENANCE_PROFILE, $effective['lib-icu']['provenance']);
        self::assertSame(TargetPlatformPackage::PROVENANCE_CLOSED_WORLD, $effective['ext-curl']['provenance']);
        self::assertSame(TargetPlatformPackage::STATE_ABSENT, $effective['ext-curl']['state']);
        self::assertSame(TargetPlatformPackage::PROVENANCE_REQUEST, $effective['ext-intl']['provenance']);
        self::assertFalse($platform->composerPlatformOverrides(['lib-host' => '1.0'])['lib-host']);
        self::assertFalse($platform->composerPlatformOverrides(['php-64bit' => '8.4.0'])['php-64bit']);
        self::assertArrayNotHasKey('composer', $platform->composerPlatformOverrides(['composer' => '2.8.12']));
        self::assertArrayNotHasKey('ext-ext-json', $platform->composerPlatformOverrides());

        $canonical = (new PlatformProvenance($request, $project, $platform))->toArray();
        self::assertSame('complete', $canonical['profile']['completeness']);
        self::assertSame('complete', $canonical['extensions']['completeness']);
        self::assertNull($canonical['extensions']['unmodeled_provenance']);
    }

    public function testCompleteProfileCanonicalDecisionIsIndependentOfHostExtensionInventory(): void
    {
        $profile = $this->profile('complete', [
            'php' => '8.3.4',
            'ext-json' => '8.3.0',
            'composer' => '2.8.12',
            'composer-plugin-api' => '2.6.0',
            'composer-runtime-api' => '2.2.2',
        ]);
        $request = new UpgradeRequest($this->projectPath(), [], null, null, [], [], 'json', null, false, [], $profile);
        $project = new ProjectState($this->projectPath(), new ComposerJson([]), new ComposerLock([]));
        $jsonHost = TargetPlatform::fromRequest($request, $project, [new HostExtension('json', '8.4.0')], '8.4.0');
        $curlHost = TargetPlatform::fromRequest($request, $project, [new HostExtension('curl', '8.2.0')], '8.4.0');

        self::assertSame(
            (new PlatformProvenance($request, $project, $jsonHost))->toArray(),
            (new PlatformProvenance($request, $project, $curlHost))->toArray()
        );
        self::assertSame(
            'profile',
            (new PlatformProvenance($request, $project, $jsonHost))->toArray()['target_php']['provenance']
        );
    }

    public function testPartialProfileRemainsHostDependentAndPreservesProjectValues(): void
    {
        $profile = $this->profile('partial', ['lib-icu' => '73.2']);
        $request = new UpgradeRequest(
            $this->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [],
            $profile
        );
        $project = new ProjectState(
            $this->projectPath(),
            new ComposerJson(['config' => ['platform' => ['ext-json' => '8.1.0']]]),
            new ComposerLock([])
        );
        $platform = TargetPlatform::fromRequest($request, $project, [new HostExtension('curl', '8.4.0')]);

        self::assertSame('8.1.0', $platform->platformPackage('ext-json')?->version());
        self::assertSame('partial', (new PlatformProvenance($request, $project, $platform))->toArray()['extensions']['completeness']);
        self::assertStringContainsString(
            'partial',
            implode(' ', (new PlatformProvenance($request, $project, $platform))->uncertainties())
        );
    }

    public function testPartialProfilePreservesPresenceOnlyUncertaintyOutsideComposerOverrides(): void
    {
        $profile = $this->profile('partial', ['lib-icu' => '73.2']);
        $request = new UpgradeRequest(
            $this->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [ExtensionAssumption::fromPresenceInput('ext-json')],
            $profile
        );
        $project = new ProjectState($this->projectPath(), new ComposerJson([]), new ComposerLock([]));
        $platform = TargetPlatform::fromRequest($request, $project, []);
        $decision = $platform->platformPackage('ext-json');
        $profileReport = $platform->profileReport();

        self::assertNotNull($decision);
        self::assertTrue($decision->isPresentWithoutVersion());
        self::assertNull($decision->toArray()['version']);
        self::assertSame('0', $platform->composerPlatformOverrides()['ext-json']);
        self::assertNotNull($profileReport);
        self::assertNull($profileReport['effective'][0]['version']);
    }

    public function testPartialProfileRejectsPresenceOnlyRequestForAProfileAbsence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contradicts its absence');

        new UpgradeRequest(
            $this->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [ExtensionAssumption::fromPresenceInput('ext-json')],
            $this->profile('partial', ['ext-json' => false])
        );
    }

    public function testContradictoryRequestAndCompletePresenceOnlyInputAreRejectedBeforeComposer(): void
    {
        try {
            new UpgradeRequest(
                $this->projectPath(),
                [new UpgradeTarget('fixture/dependency', '^2.0')],
                null,
                null,
                [],
                [],
                'json',
                null,
                false,
                [ExtensionAssumption::fromAbsenceInput('ext-json')],
                $this->profile('partial', ['ext-json' => '8.3.0'])
            );
            self::fail('Expected contradictory request and profile values to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('contradicts', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('presence-only');
        new UpgradeRequest(
            $this->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [ExtensionAssumption::fromPresenceInput('ext-json')],
            $this->profile('complete', ['php' => '8.3.4', 'ext-json' => '8.3.0'])
        );
    }

    public function testPlatformQueriesCoverUnsupportedConfigAbsenceAndToolchainDecisions(): void
    {
        $project = new ProjectState(
            $this->projectPath(),
            new ComposerJson(['config' => ['platform' => ['vendor/package' => '1.0.0']]]),
            new ComposerLock([])
        );
        $plainRequest = new UpgradeRequest(
            $this->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')]
        );
        $plain = TargetPlatform::fromRequest($plainRequest, $project, []);

        self::assertNull($plain->platformPackage('vendor/package'));
        self::assertFalse($plain->hasAbsentExtensionAssumptions());
        self::assertFalse($plain->hasAbsentPlatformPackages());

        $absenceRequest = new UpgradeRequest(
            $this->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [ExtensionAssumption::fromAbsenceInput('ext-xdebug')]
        );
        $absence = TargetPlatform::fromRequest($absenceRequest, $project, []);
        self::assertTrue($absence->hasAbsentExtensionAssumptions());
        self::assertTrue($absence->hasAbsentPlatformPackages());

        $toolchainProfile = $this->profile('partial', ['composer' => false]);
        $toolchainRequest = new UpgradeRequest(
            $this->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [],
            $toolchainProfile
        );
        $toolchain = TargetPlatform::fromRequest($toolchainRequest, $project, []);

        self::assertStringContainsString(
            'cannot be modeled absent',
            (string) $toolchain->toolchainValidationFailure(['composer' => '2.8.12'])
        );
        self::assertNull($toolchain->toolchainValidationFailure([]));
        self::assertStringContainsString(
            'toolchain-bound',
            implode(' ', (new PlatformProvenance($toolchainRequest, $project, $toolchain))->uncertainties())
        );

        $completeRequest = new UpgradeRequest(
            $this->projectPath(),
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [],
            $this->profile('complete', ['php' => '8.3.4'])
        );
        self::assertTrue(TargetPlatform::fromRequest($completeRequest, $project, [])->hasAbsentPlatformPackages());
    }

    /** @param array<string, string|false> $packages */
    private function profile(string $completeness, array $packages): TargetPlatformProfile
    {
        return TargetPlatformProfile::fromArray([
            'schema_version' => '1.0',
            'completeness' => $completeness,
            'packages' => $packages,
        ]);
    }

    private function projectPath(): string
    {
        return dirname(__DIR__, 5) . '/tests/fixtures/project-isolation';
    }
}
