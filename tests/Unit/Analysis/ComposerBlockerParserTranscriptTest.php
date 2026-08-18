<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\ComposerBlockerParser;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class ComposerBlockerParserTranscriptTest extends TestCase
{
    /**
     * @dataProvider transcriptProvider
     * @param array{absent_extension_simulation: bool, locked_prohibits: bool} $capabilities
     * @param array{
     *     name: string,
     *     kind: string,
     *     transcript: string,
     *     transcript_sha256: string,
     *     targets: list<array{package: string, constraint: string}>,
     *     diagnostic?: array{package: string, constraint: string, command: list<string>},
     *     expected: list<array<string, mixed>>
     * } $case
     */
    public function testSupportedComposerTranscriptParsesToStableBlockers(
        string $composerVersion,
        array $capabilities,
        array $case
    ): void {
        self::assertSame(
            version_compare($composerVersion, '2.2.0', '>='),
            $capabilities['absent_extension_simulation'],
            'Fixture capability metadata must track the Composer 2.2 absent-extension boundary.'
        );
        self::assertSame(
            version_compare($composerVersion, '2.4.0', '>='),
            $capabilities['locked_prohibits'],
            'Fixture capability metadata must track the Composer 2.4 locked-prohibits boundary.'
        );

        $transcriptPath = $this->fixtureRoot() . '/' . $case['transcript'];
        $transcript = file_get_contents($transcriptPath);
        self::assertNotFalse($transcript, sprintf('Could not read transcript fixture %s.', $transcriptPath));
        self::assertNotSame('', trim($transcript), sprintf('Transcript fixture %s is empty.', $transcriptPath));
        self::assertSame(
            $case['transcript_sha256'],
            hash('sha256', $transcript),
            sprintf(
                'Solver/repository transcript drift for Composer %s fixture %s. Refresh the pinned transcript hash before evaluating parser drift.',
                $composerVersion,
                $case['name']
            )
        );

        $targets = array_map(
            static fn (array $target): UpgradeTarget => new UpgradeTarget($target['package'], $target['constraint']),
            $case['targets']
        );
        $scenario = new Scenario($case['name'], new UpgradeTargetSet($targets));
        $diagnostics = [];
        $stderr = $transcript;

        if ($case['kind'] === 'prohibits') {
            self::assertArrayHasKey('diagnostic', $case);
            $diagnostic = $case['diagnostic'];
            self::assertSame(
                $capabilities['locked_prohibits'],
                in_array('--locked', $diagnostic['command'], true),
                'Only Composer versions supporting locked diagnostics may use --locked fixtures.'
            );
            $diagnostics[] = new ComposerDiagnostic(
                $diagnostic['package'],
                $diagnostic['constraint'],
                $diagnostic['command'],
                0,
                $transcript,
                ''
            );
            $stderr = 'Your requirements could not be resolved to an installable set of packages.';
        } else {
            self::assertSame('solver', $case['kind']);
        }

        $result = new ScenarioResult(
            $scenario,
            2,
            '',
            $stderr,
            null,
            null,
            ScenarioResult::FAILURE_SOLVER,
            $composerVersion,
            [],
            0,
            null,
            $diagnostics
        );
        $blockers = (new ComposerBlockerParser())->parse($result, 'transcript-fixture');

        self::assertCount(count($case['expected']), $blockers);
        foreach ($case['expected'] as $index => $expected) {
            self::assertInstanceOf(Blocker::class, $blockers[$index]);
            self::assertSame(
                $expected,
                array_intersect_key($blockers[$index]->toArray(), $expected),
                sprintf('Parser drift for Composer %s fixture %s.', $composerVersion, $case['name'])
            );
        }
    }

    public function testManifestCoversEverySupportedComposerDiagnosticProfile(): void
    {
        $manifest = $this->manifest();
        self::assertSame(2, $manifest['schema_version'] ?? null);
        self::assertIsArray($manifest['policy'] ?? null);
        $policy = $manifest['policy'];
        self::assertSame(
            ComposerExecutionConfiguration::DEFAULT_EXPECTED_VERSION,
            $policy['supported_runtime_constraint'] ?? null
        );
        self::assertSame(1, $policy['parser_contract_version'] ?? null);
        self::assertSame([
            [
                'id' => 'composer-2.0-unlocked',
                'supported_range' => '>=2.0.0 <2.2.0',
                'fixture_version' => '2.0.14',
            ],
            [
                'id' => 'composer-2.2-platform-unlocked',
                'supported_range' => '>=2.2.0 <2.4.0',
                'fixture_version' => '2.2.25',
            ],
            [
                'id' => 'composer-2.4-locked',
                'supported_range' => '>=2.4.0 <3.0.0',
                'fixture_version' => '2.4.4',
            ],
            [
                'id' => 'current-pinned-output',
                'supported_range' => '2.8.12',
                'fixture_version' => '2.8.12',
            ],
        ], $policy['diagnostic_profiles'] ?? null);

        self::assertIsArray($manifest['versions'] ?? null);
        $versions = [];
        foreach ($manifest['versions'] as $versionFixture) {
            self::assertIsArray($versionFixture);
            self::assertIsString($versionFixture['composer_version'] ?? null);
            self::assertIsArray($versionFixture['cases'] ?? null);
            $kinds = [];
            foreach ($versionFixture['cases'] as $case) {
                self::assertIsArray($case);
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $case['transcript_sha256'] ?? '');
                $kinds[] = $case['kind'] ?? null;
            }
            sort($kinds);
            self::assertSame(['prohibits', 'solver'], array_values(array_unique($kinds)));
            $versions[] = $versionFixture['composer_version'];
        }

        self::assertSame(
            array_column($policy['diagnostic_profiles'], 'fixture_version'),
            $versions,
            'Composer diagnostic profile policy and transcript fixtures must change together.'
        );
    }

    /**
     * @return list<array{
     *     string,
     *     array{absent_extension_simulation: bool, locked_prohibits: bool},
     *     array<string, mixed>
     * }>
     */
    public function transcriptProvider(): array
    {
        $manifest = $this->manifest();
        self::assertIsArray($manifest['versions'] ?? null);

        $rows = [];
        foreach ($manifest['versions'] as $versionFixture) {
            self::assertIsArray($versionFixture);
            self::assertIsString($versionFixture['composer_version'] ?? null);
            self::assertIsArray($versionFixture['capabilities'] ?? null);
            self::assertIsArray($versionFixture['cases'] ?? null);
            foreach ($versionFixture['cases'] as $case) {
                self::assertIsArray($case);
                $rows[] = [
                    $versionFixture['composer_version'],
                    $versionFixture['capabilities'],
                    $case,
                ];
            }
        }

        return $rows;
    }

    public function testEmptyDiagnosticOutputFallsBackWithoutInventingABlocker(): void
    {
        $scenario = new Scenario('diagnostic-empty', new UpgradeTargetSet([
            new UpgradeTarget('fixture/dependency', '^2.0'),
        ]));
        $diagnostic = new ComposerDiagnostic('fixture/dependency', '^2.0', ['composer', 'prohibits'], 0, '', '');
        $result = new ScenarioResult(
            $scenario,
            2,
            '',
            '',
            null,
            null,
            ScenarioResult::FAILURE_SOLVER,
            '2.8.12',
            [],
            0,
            null,
            [$diagnostic]
        );

        $blockers = (new ComposerBlockerParser())->parse($result, 'diagnostic-empty');

        self::assertCount(1, $blockers);
        self::assertSame('unknown-composer-failure', $blockers[0]->type());
    }

    public function testDiagnosticFallbackUsesTheFirstRelationWhenTheSubjectIsNotInTheTranscript(): void
    {
        $result = $this->diagnosticResult(
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            'fixture/dependency',
            'fixture/blocker 1.0.0 requires fixture/unrelated (^1.0)'
        );

        $blockers = (new ComposerBlockerParser())->parse($result, 'diagnostic-fallback');

        self::assertCount(1, $blockers);
        self::assertSame('fixture/unrelated', $blockers[0]->subject());
    }

    public function testDiagnosticDoesNotBlameAPackageExplicitlyIncludedInTheUpdate(): void
    {
        $result = $this->diagnosticResult(
            [
                new UpgradeTarget('fixture/dependency', '^2.0'),
                new UpgradeTarget('fixture/blocker', '^2.0'),
            ],
            'fixture/dependency',
            'fixture/blocker 1.0.0 requires fixture/dependency (^1.0)'
        );

        $blockers = (new ComposerBlockerParser())->parse($result, 'diagnostic-updated-blocker');

        self::assertCount(1, $blockers);
        self::assertSame('unknown-composer-failure', $blockers[0]->type());
    }

    public function testDiagnosticMayUseTheSubjectPackageAsTheDependencyRoot(): void
    {
        $result = $this->diagnosticResult(
            [new UpgradeTarget('fixture/blocker', '^2.0')],
            'fixture/blocker',
            'fixture/blocker 1.0.0 requires fixture/dependency (^1.0)'
        );

        $blockers = (new ComposerBlockerParser())->parse($result, 'diagnostic-subject-root');

        self::assertCount(1, $blockers);
        self::assertSame('fixture/blocker', $blockers[0]->blocker());
    }

    /** @param list<UpgradeTarget> $targets */
    private function diagnosticResult(array $targets, string $subject, string $output): ScenarioResult
    {
        $scenario = new Scenario('diagnostic-branches', new UpgradeTargetSet($targets));
        $diagnostic = new ComposerDiagnostic($subject, '^2.0', ['composer', 'prohibits'], 0, $output, '');

        return new ScenarioResult(
            $scenario,
            2,
            '',
            'Your requirements could not be resolved to an installable set of packages.',
            null,
            null,
            ScenarioResult::FAILURE_SOLVER,
            '2.8.12',
            [],
            0,
            null,
            [$diagnostic]
        );
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 5) . '/tests/fixtures/composer-transcripts';
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $manifestPath = $this->fixtureRoot() . '/manifest.json';
        $contents = file_get_contents($manifestPath);
        self::assertNotFalse($contents, sprintf('Could not read transcript manifest %s.', $manifestPath));
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }
}
