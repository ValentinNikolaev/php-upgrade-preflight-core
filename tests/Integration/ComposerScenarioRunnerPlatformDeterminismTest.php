<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Integration;

use PhpUpgradePreflight\Core\Analysis\BlockerGrouper;
use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\ExtensionAssumption;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Tests\Support\FixtureSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class ComposerScenarioRunnerPlatformDeterminismTest extends TestCase
{
    protected function setUp(): void
    {
        $version = $this->composerVersion();
        if ($version === null) {
            self::markTestSkipped('Composer is required for the platform-determinism integration test.');
        }
        if (version_compare($version, '2.2.0', '<')) {
            self::markTestSkipped('Composer 2.2 or newer is required for deterministic absent-extension fixtures.');
        }
    }

    public function testExplicitlyPresentRequiredExtensionResolvesOffline(): void
    {
        [$result, $blockers, $platform] = $this->runFixture(
            'fixture/extension-required',
            [ExtensionAssumption::fromPresenceInput('ext-preflight-required:1.2.3')]
        );

        self::assertTrue($result->succeeded(), $result->stdout() . $result->stderr());
        self::assertSame([], $blockers);
        $lock = $result->lock();
        self::assertNotNull($lock);
        $package = $lock->package('fixture/extension-required');
        self::assertNotNull($package);
        self::assertSame('1.0.0', $package->version());
        self::assertTrue($package->isDirect());
        $this->assertAssumption(
            $platform,
            'ext-preflight-required',
            ['name' => 'ext-preflight-required', 'state' => 'present', 'version' => '1.2.3', 'provenance' => 'request']
        );
    }

    public function testExplicitlyMissingExtensionProducesAReproducibleBlocker(): void
    {
        [$result, $blockers, $platform] = $this->runFixture(
            'fixture/extension-missing',
            [ExtensionAssumption::fromAbsenceInput('ext-preflight-missing')]
        );

        self::assertTrue($result->isSolverFailure(), $result->stdout() . $result->stderr());
        self::assertStringContainsString('missing from your system', strtolower($result->stdout() . $result->stderr()));
        $this->assertExtensionBlocker(
            $blockers,
            'extension-missing',
            'ext-preflight-missing',
            '*'
        );
        $this->assertAssumption(
            $platform,
            'ext-preflight-missing',
            ['name' => 'ext-preflight-missing', 'state' => 'absent', 'version' => null, 'provenance' => 'request']
        );
    }

    public function testLoadedExtensionCanBeDisabledDeterministically(): void
    {
        [$result, $blockers, $platform] = $this->runFixture(
            'fixture/extension-disabled',
            [ExtensionAssumption::fromAbsenceInput('ext-json')]
        );

        self::assertTrue($result->isSolverFailure(), $result->stdout() . $result->stderr());
        self::assertStringContainsString('disabled by your platform config', strtolower($result->stdout() . $result->stderr()));
        $this->assertExtensionBlocker($blockers, 'extension-missing', 'ext-json', '*');
        $this->assertAssumption(
            $platform,
            'ext-json',
            ['name' => 'ext-json', 'state' => 'absent', 'version' => null, 'provenance' => 'request']
        );
    }

    public function testIncompatibleExactExtensionVersionProducesAReproducibleBlocker(): void
    {
        [$result, $blockers, $platform] = $this->runFixture(
            'fixture/extension-versioned',
            [ExtensionAssumption::fromPresenceInput('ext-preflight-versioned:1.0.0')]
        );

        self::assertTrue($result->isSolverFailure(), $result->stdout() . $result->stderr());
        self::assertStringContainsString('wrong version installed', strtolower($result->stdout() . $result->stderr()));
        $this->assertExtensionBlocker(
            $blockers,
            'extension-version-incompatible',
            'ext-preflight-versioned',
            '^2.0'
        );
        $this->assertAssumption(
            $platform,
            'ext-preflight-versioned',
            ['name' => 'ext-preflight-versioned', 'state' => 'present', 'version' => '1.0.0', 'provenance' => 'request']
        );
    }

    public function testCompatibleExactExtensionVersionResolvesOffline(): void
    {
        [$result, $blockers, $platform] = $this->runFixture(
            'fixture/extension-versioned',
            [ExtensionAssumption::fromPresenceInput('ext-preflight-versioned:2.1.0')]
        );

        self::assertTrue($result->succeeded(), $result->stdout() . $result->stderr());
        self::assertSame([], $blockers);
        self::assertNotNull($result->lock()?->package('fixture/extension-versioned'));
        $this->assertAssumption(
            $platform,
            'ext-preflight-versioned',
            ['name' => 'ext-preflight-versioned', 'state' => 'present', 'version' => '2.1.0', 'provenance' => 'request']
        );
    }

    /**
     * @param list<ExtensionAssumption> $assumptions
     * @return array{0: ScenarioResult, 1: list<Blocker>, 2: TargetPlatform}
     */
    private function runFixture(string $package, array $assumptions): array
    {
        $projectPath = $this->projectPath();
        $snapshot = FixtureSnapshot::capture(dirname($projectPath));
        $project = (new ProjectStateBuilder())->build($projectPath);
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget($package, '1.0.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            $assumptions
        );
        $platform = TargetPlatform::fromRequest($request, $project);
        $result = (new ComposerScenarioRunner())->run(
            $project,
            $request,
            new Scenario('extension-platform-fixture', $request->targets(), false),
            $platform
        );
        $blockers = (new BlockerGrouper())->group(
            [$result],
            new EvidenceLedger(),
            $project->composerLock(),
            [],
            $platform
        );

        $snapshot->assertUnchanged($this);

        return [$result, $blockers, $platform];
    }

    /** @param list<Blocker> $blockers */
    private function assertExtensionBlocker(
        array $blockers,
        string $type,
        string $subject,
        string $constraint
    ): void {
        self::assertCount(1, $blockers);
        self::assertSame($type, $blockers[0]->type());
        self::assertSame($subject, $blockers[0]->subject());
        self::assertSame($constraint, $blockers[0]->conflict());
        self::assertSame('high', $blockers[0]->confidence());
        self::assertTrue($blockers[0]->blocksResolution());
    }

    /** @param array{name: string, state: string, version: ?string, provenance: string} $expected */
    private function assertAssumption(TargetPlatform $platform, string $name, array $expected): void
    {
        $assumption = $platform->extensionAssumption($name);
        self::assertNotNull($assumption);
        self::assertSame($expected, $assumption->toArray());
    }

    private function projectPath(): string
    {
        return dirname(__DIR__, 4)
            . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'path-repository'
            . DIRECTORY_SEPARATOR . 'project';
    }

    private function composerVersion(): ?string
    {
        $composer = (new ExecutableFinder())->find('composer');
        if ($composer === null) {
            return null;
        }

        $process = new Process([$composer, '--version', '--no-ansi']);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }

        if (preg_match('/\bComposer(?:\s+version)?\s+([^\s]+)/i', $process->getOutput() . $process->getErrorOutput(), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
