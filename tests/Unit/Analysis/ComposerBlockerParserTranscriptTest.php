<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\ComposerBlockerParser;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
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
                sprintf('Unexpected blocker parsed from Composer %s fixture %s.', $composerVersion, $case['name'])
            );
        }
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
        $manifestPath = $this->fixtureRoot() . '/manifest.json';
        $contents = file_get_contents($manifestPath);
        self::assertNotFalse($contents, sprintf('Could not read transcript manifest %s.', $manifestPath));
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['schema_version'] ?? null);
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

    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 5) . '/tests/fixtures/composer-transcripts';
    }
}
