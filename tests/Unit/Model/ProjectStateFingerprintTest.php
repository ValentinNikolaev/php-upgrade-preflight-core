<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\HostExtension;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\ProjectStateFingerprint;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\TargetPlatformProfile;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PHPUnit\Framework\TestCase;

final class ProjectStateFingerprintTest extends TestCase
{
    public function testCompleteProfileFingerprintIsIndependentOfHostExtensionInventory(): void
    {
        $profile = $this->profile('complete', ['php' => '8.3.4']);
        $state = $this->projectState();
        $request = new UpgradeRequest($this->projectPath(), [], null, null, [], [], 'json', null, false, [], $profile);
        $jsonHost = TargetPlatform::fromRequest(
            $request,
            $state,
            [new HostExtension('json', '8.3.4')],
            '8.3.4'
        );
        $curlHost = TargetPlatform::fromRequest(
            $request,
            $state,
            [new HostExtension('curl', '8.3.4')],
            '8.3.4'
        );

        self::assertNotSame(
            $jsonHost->composerPlatformOverrides(),
            $curlHost->composerPlatformOverrides(),
            'Execution overrides must still suppress the packages discovered on each analyzer host.'
        );

        $jsonFingerprint = $this->fingerprint($state, $jsonHost)->toArray();
        $curlFingerprint = $this->fingerprint($state, $curlHost)->toArray();

        self::assertSame($jsonFingerprint['platform_sha256'], $curlFingerprint['platform_sha256']);
        self::assertSame($jsonFingerprint['state_sha256'], $curlFingerprint['state_sha256']);
    }

    public function testMeaningfulCompleteProfileDecisionChangesPlatformAndStateHashes(): void
    {
        $state = $this->projectState();
        $first = $this->platform($state, $this->profile('complete', [
            'php' => '8.3.4',
            'lib-icu' => '73.2',
        ]));
        $second = $this->platform($state, $this->profile('complete', [
            'php' => '8.3.4',
            'lib-icu' => '74.1',
        ]));

        $firstFingerprint = $this->fingerprint($state, $first)->toArray();
        $secondFingerprint = $this->fingerprint($state, $second)->toArray();

        self::assertNotSame($firstFingerprint['platform_sha256'], $secondFingerprint['platform_sha256']);
        self::assertNotSame($firstFingerprint['state_sha256'], $secondFingerprint['state_sha256']);
    }

    public function testCompleteProfileTreatsExplicitAndImplicitSafeAbsenceAsTheSameDecision(): void
    {
        $state = $this->projectState();
        $implicitAbsence = $this->platform($state, $this->profile('complete', ['php' => '8.3.4']));
        $explicitAbsence = $this->platform($state, $this->profile('complete', [
            'php' => '8.3.4',
            'ext-json' => false,
        ]));

        $implicitFingerprint = $this->fingerprint($state, $implicitAbsence)->toArray();
        $explicitFingerprint = $this->fingerprint($state, $explicitAbsence)->toArray();

        self::assertSame($implicitFingerprint['platform_sha256'], $explicitFingerprint['platform_sha256']);
        self::assertSame($implicitFingerprint['state_sha256'], $explicitFingerprint['state_sha256']);
    }

    public function testPartialProfileFingerprintIncludesExplicitlyModeledDecisions(): void
    {
        $state = $this->projectState();
        $first = $this->platform($state, $this->profile('partial', [
            'php' => '8.3.4',
            'ext-intl' => '8.3.0',
        ]));
        $second = $this->platform($state, $this->profile('partial', [
            'php' => '8.3.4',
            'ext-intl' => false,
        ]));

        $firstFingerprint = $this->fingerprint($state, $first)->toArray();
        $secondFingerprint = $this->fingerprint($state, $second)->toArray();

        self::assertNotSame($firstFingerprint['platform_sha256'], $secondFingerprint['platform_sha256']);
        self::assertNotSame($firstFingerprint['state_sha256'], $secondFingerprint['state_sha256']);
    }

    public function testExplicitComposerExecutableIdentityChangesExecutionAndStateHashesWithoutExposingItsPath(): void
    {
        $state = $this->projectState();
        $platform = $this->platform($state, $this->profile('partial', ['php' => '8.3.4']));
        $firstExecution = ComposerExecutionConfiguration::restricted('/private/tools/composer-a');
        $secondExecution = ComposerExecutionConfiguration::restricted('/private/tools/composer-b');

        $first = ProjectStateFingerprint::fromState(
            $state,
            $platform,
            '8.3.4',
            $firstExecution->stateFingerprintData()
        )->toArray();
        $second = ProjectStateFingerprint::fromState(
            $state,
            $platform,
            '8.3.4',
            $secondExecution->stateFingerprintData()
        )->toArray();

        self::assertNotSame($first['execution_policy_sha256'], $second['execution_policy_sha256']);
        self::assertNotSame($first['state_sha256'], $second['state_sha256']);
        self::assertStringNotContainsString('/private/tools', json_encode([$first, $second], JSON_THROW_ON_ERROR));
    }

    /**
     * A candidate state has to be identifiable by what it contains, not by where the
     * project happened to be analyzed. Composer derives the lock `content-hash` from
     * the manifest the analyzer wrote into its workspace, which carries absolute path
     * repositories, and it records those repositories in every resolved package, so
     * both differ between two hosts analyzing the same project.
     */
    public function testCandidateStateFingerprintsAreIndependentOfTheHostAndItsSeparators(): void
    {
        $linux = $this->candidateState('/home/runner/work/app/target', '/home/runner/work/app', '/', 'a1b2c3');
        $windows = $this->candidateState('D:\\a\\app\\target', 'D:\\a\\app', '\\', 'd4e5f6');

        $linuxFingerprint = $this->fingerprint($linux, $this->platform($linux, $this->profile('partial', [
            'php' => '8.3.4',
        ])))->toArray();
        $windowsFingerprint = $this->fingerprint($windows, $this->platform($windows, $this->profile('partial', [
            'php' => '8.3.4',
        ])))->toArray();

        self::assertSame($linuxFingerprint['manifest_sha256'], $windowsFingerprint['manifest_sha256']);
        self::assertSame($linuxFingerprint['lock_sha256'], $windowsFingerprint['lock_sha256']);
        self::assertSame($linuxFingerprint['state_sha256'], $windowsFingerprint['state_sha256']);
    }

    public function testALockedVersionChangeStillChangesTheStateFingerprint(): void
    {
        $state = $this->candidateState('/home/runner/work/app/target', '/home/runner/work/app', '/', 'a1b2c3');
        $upgraded = $this->candidateState(
            '/home/runner/work/app/target',
            '/home/runner/work/app',
            '/',
            'a1b2c3',
            '13.0.0'
        );

        self::assertNotSame(
            $this->fingerprint($state, $this->platform($state, $this->profile('partial', ['php' => '8.3.4'])))
                ->stateSha256(),
            $this->fingerprint($upgraded, $this->platform($upgraded, $this->profile('partial', ['php' => '8.3.4'])))
                ->stateSha256()
        );
    }

    private function candidateState(
        string $projectPath,
        string $repositoryRoot,
        string $separator,
        string $contentHash,
        string $lockedVersion = '12.0.0'
    ): ProjectState {
        return new ProjectState(
            $projectPath,
            new ComposerJson([
                'name' => 'fixture/fingerprint',
                'repositories' => [['type' => 'path', 'url' => '../repository/*']],
                'require' => ['laravel/framework' => '^12.0'],
            ]),
            new ComposerLock([
                'content-hash' => $contentHash,
                'packages' => [[
                    'name' => 'laravel/framework',
                    'version' => $lockedVersion,
                    'dist' => [
                        'type' => 'path',
                        'url' => $repositoryRoot . $separator . 'repository' . $separator . 'framework-12',
                    ],
                    'transport-options' => ['symlink' => false, 'relative' => true],
                ]],
                'packages-dev' => [],
                'prefer-stable' => false,
                'plugin-api-version' => '2.6.0',
                'time' => null,
            ])
        );
    }

    private function platform(ProjectState $state, TargetPlatformProfile $profile): TargetPlatform
    {
        $request = new UpgradeRequest($this->projectPath(), [], null, null, [], [], 'json', null, false, [], $profile);

        return TargetPlatform::fromRequest($request, $state, [], '8.3.4');
    }

    private function fingerprint(ProjectState $state, TargetPlatform $platform): ProjectStateFingerprint
    {
        return ProjectStateFingerprint::fromState(
            $state,
            $platform,
            '8.3.4',
            ['composer' => ['no_plugins' => true, 'no_scripts' => true]]
        );
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

    private function projectState(): ProjectState
    {
        return new ProjectState(
            $this->projectPath(),
            new ComposerJson(['name' => 'fixture/fingerprint']),
            new ComposerLock([])
        );
    }

    private function projectPath(): string
    {
        return dirname(__DIR__, 5) . '/tests/fixtures/project-isolation';
    }
}
