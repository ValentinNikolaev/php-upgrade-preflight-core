<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\BlockerGrouper;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\ExtensionAssumption;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class BlockerGrouperTest extends TestCase
{
    /**
     * @dataProvider solverOutputProvider
     * @param list<UpgradeTarget> $targets
     * @param array<string, mixed> $expected
     */
    public function testItParsesStructuredBlockerFields(string $output, array $targets, array $expected): void
    {
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario($targets), 2, '', $output, null, null, ScenarioResult::FAILURE_SOLVER),
        ], $evidence);

        self::assertCount(1, $blockers);
        foreach ($expected as $accessor => $value) {
            self::assertSame($value, $blockers[0]->{$accessor}());
        }
        self::assertNotSame([], $blockers[0]->options());
        self::assertSame(['solver-1'], $blockers[0]->evidence());
        self::assertCount(1, $evidence->all());
        self::assertSame('solver-1', $evidence->all()[0]->id());
        self::assertSame('test', $evidence->all()[0]->context()['scenario']);
        self::assertSame(2, $evidence->all()[0]->context()['exit_code']);
        self::assertSame($output, $evidence->all()[0]->context()['output_excerpt']);
    }

    /** @return list<array{string, list<UpgradeTarget>, array<string, mixed>}> */
    public function solverOutputProvider(): array
    {
        return [
            [
                'vendor/package 2.0.0 requires php >=8.2 -> your php version (8.1.0) does not satisfy that requirement.',
                [new UpgradeTarget('php', '8.1')],
                [
                    'type' => 'php-platform-too-low',
                    'subject' => 'php',
                    'requestedConstraint' => '8.1.0',
                    'blocker' => 'vendor/package',
                    'lockedVersion' => '2.0.0',
                    'conflict' => '>=8.2',
                    'dependencyPath' => ['vendor/package', 'php'],
                    'confidence' => 'high',
                ],
            ],
            [
                'vendor/legacy 1.4.0 requires php ^7.4 -> your php version (8.2.0) does not satisfy that requirement.',
                [new UpgradeTarget('php', '8.2')],
                [
                    'type' => 'php-platform-too-high',
                    'subject' => 'php',
                    'requestedConstraint' => '8.2.0',
                    'blocker' => 'vendor/legacy',
                    'lockedVersion' => '1.4.0',
                    'conflict' => '^7.4',
                ],
            ],
            [
                'vendor/legacy 1.4.0 requires php >=7.2 <8.0 -> your php version (8.2.0) does not satisfy that requirement.',
                [new UpgradeTarget('php', '8.2')],
                [
                    'type' => 'php-platform-too-high',
                    'subject' => 'php',
                    'requestedConstraint' => '8.2.0',
                    'blocker' => 'vendor/legacy',
                    'lockedVersion' => '1.4.0',
                    'conflict' => '>=7.2 <8.0',
                ],
            ],
            [
                '- Root composer.json requires Vendor/Package ^1.0',
                [new UpgradeTarget('vendor/package', '^2.0')],
                [
                    'type' => 'root-constraint-conflict',
                    'subject' => 'vendor/package',
                    'requestedConstraint' => '^2.0',
                    'conflict' => '^1.0',
                ],
            ],
            [
                'vendor/blocker 1.0.0 requires ext-fixture * -> it is missing from your system.',
                [new UpgradeTarget('vendor/package', '^2.0')],
                [
                    'type' => 'extension-missing',
                    'subject' => 'ext-fixture',
                    'blocker' => 'vendor/blocker',
                    'lockedVersion' => '1.0.0',
                    'conflict' => '*',
                ],
            ],
            [
                'Could not find package vendor/missing.',
                [new UpgradeTarget('vendor/missing', '^2.0')],
                [
                    'type' => 'package-not-found',
                    'subject' => 'vendor/missing',
                    'requestedConstraint' => '^2.0',
                ],
            ],
            [
                'Root composer.json requires vendor/missing ^2.0, found vendor/missing[1.0.0, 1.5.0] but these do not match the constraint.',
                [new UpgradeTarget('vendor/missing', '^2.0')],
                [
                    'type' => 'package-not-found',
                    'subject' => 'vendor/missing',
                    'requestedConstraint' => '^2.0',
                    'confidence' => 'high',
                ],
            ],
            [
                'The package does not match your minimum-stability.',
                [new UpgradeTarget('vendor/unstable', 'dev-main')],
                [
                    'type' => 'minimum-stability-conflict',
                    'subject' => 'vendor/unstable',
                    'requestedConstraint' => 'dev-main',
                    'conflict' => 'minimum-stability',
                    'confidence' => 'medium',
                ],
            ],
            [
                'vendor/replacer 2.0.0 replaces vendor/subject (self.version)',
                [new UpgradeTarget('vendor/subject', '^2.0')],
                [
                    'type' => 'replace-provide-conflict',
                    'subject' => 'vendor/subject',
                    'requestedConstraint' => '^2.0',
                    'blocker' => 'vendor/replacer',
                    'lockedVersion' => '2.0.0',
                    'conflict' => 'self.version',
                ],
            ],
            [
                'vendor/locked is locked to version 1.2.3 and an update of this package was not requested.',
                [new UpgradeTarget('vendor/package', '^2.0')],
                [
                    'type' => 'transitive-package-conflict',
                    'subject' => 'vendor/package',
                    'requestedConstraint' => '^2.0',
                    'blocker' => 'vendor/locked',
                    'lockedVersion' => '1.2.3',
                    'dependencyPath' => ['vendor/package', 'vendor/locked'],
                ],
            ],
            [
                '- Root composer.json requires vendor/package ^2.0',
                [new UpgradeTarget('vendor/package', '^2.0')],
                [
                    'type' => 'unknown-composer-failure',
                    'subject' => 'vendor/package',
                    'requestedConstraint' => '^2.0',
                    'conflict' => null,
                    'confidence' => 'low',
                ],
            ],
            [
                'Package vendor/legacy is abandoned, you should avoid using it. Use vendor/replacement instead.',
                [new UpgradeTarget('vendor/legacy', '^1.0')],
                [
                    'type' => 'abandoned-package',
                    'subject' => 'vendor/legacy',
                    'requestedConstraint' => '^1.0',
                ],
            ],
            [
                'Your requirements could not be resolved.',
                [new UpgradeTarget('vendor/package', '^2.0')],
                [
                    'type' => 'unknown-composer-failure',
                    'subject' => 'vendor/package',
                    'requestedConstraint' => '^2.0',
                    'confidence' => 'low',
                ],
            ],
        ];
    }

    public function testItUsesProhibitsTreeForTheDependencyPath(): void
    {
        $scenario = $this->scenario([new UpgradeTarget('fixture/dependency', '^2.0')]);
        $diagnostic = new ComposerDiagnostic(
            'fixture/dependency',
            '^2.0',
            ['composer', 'prohibits', 'fixture/dependency', '^2.0', '--tree', '--locked'],
            0,
            "root/package 1.0.0 requires fixture/blocker (^1.0)\nfixture/blocker 1.2.3 requires fixture/dependency (^1.0)",
            ''
        );
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($scenario, 2, '', 'Resolution failed.', null, null, ScenarioResult::FAILURE_SOLVER, null, [], 0, null, [$diagnostic]),
        ], $evidence);

        self::assertCount(1, $blockers);
        self::assertSame('transitive-package-conflict', $blockers[0]->type());
        self::assertSame('fixture/dependency', $blockers[0]->subject());
        self::assertSame('^2.0', $blockers[0]->requestedConstraint());
        self::assertSame('fixture/blocker', $blockers[0]->blocker());
        self::assertSame('1.2.3', $blockers[0]->lockedVersion());
        self::assertSame('^1.0', $blockers[0]->conflict());
        self::assertSame(['root/package', 'fixture/blocker', 'fixture/dependency'], $blockers[0]->dependencyPath());
        self::assertSame($diagnostic->toArray(), $evidence->all()[0]->context()['diagnostics'][0]);
    }

    public function testItParsesNativeComposerTreeRelationshipSyntax(): void
    {
        $scenario = $this->scenario([new UpgradeTarget('php', '9.0')]);
        $diagnostic = new ComposerDiagnostic(
            'php',
            '9.0.0',
            ['composer', 'prohibits', 'php', '9.0.0', '--tree', '--locked'],
            0,
            implode("\n", [
                'php 8.3.33 The PHP interpreter',
                '|--root/project (requires php ^8.0)',
                '`--fixture/dependency 1.0.0 (requires php ^8.0)',
                '   `--root/project (requires fixture/dependency ^1.0)',
            ]),
            ''
        );
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($scenario, 2, '', 'Resolution failed.', null, null, ScenarioResult::FAILURE_SOLVER, null, [], 0, null, [$diagnostic]),
        ], $evidence);

        self::assertCount(1, $blockers);
        self::assertSame('php-platform-too-high', $blockers[0]->type());
        self::assertSame('php', $blockers[0]->subject());
        self::assertSame('9.0.0', $blockers[0]->requestedConstraint());
        self::assertSame('root/project', $blockers[0]->blocker());
        self::assertNull($blockers[0]->lockedVersion());
        self::assertSame('^8.0', $blockers[0]->conflict());
        self::assertSame(['root/project', 'php'], $blockers[0]->dependencyPath());
    }

    public function testItCorrelatesALockedPackageWithTheRequestedTarget(): void
    {
        $output = implode("\n", [
            'vendor/package 2.0.0 requires vendor/intermediate (^2.0)',
            'vendor/intermediate 2.0.0 requires vendor/locked (^2.0)',
            'vendor/locked is locked to version 1.2.3 and an update of this package was not requested.',
        ]);
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario([new UpgradeTarget('vendor/package', '^2.0')]), 2, '', $output, null, null, ScenarioResult::FAILURE_SOLVER),
        ], $evidence);

        self::assertCount(1, $blockers);
        self::assertSame('vendor/package', $blockers[0]->subject());
        self::assertSame('^2.0', $blockers[0]->requestedConstraint());
        self::assertSame('vendor/locked', $blockers[0]->blocker());
        self::assertSame('1.2.3', $blockers[0]->lockedVersion());
        self::assertSame('^2.0', $blockers[0]->conflict());
        self::assertSame(['vendor/package', 'vendor/intermediate', 'vendor/locked'], $blockers[0]->dependencyPath());
    }

    public function testItParsesEveryComposerProblemSection(): void
    {
        $output = implode("\n", [
            'Your requirements could not be resolved to an installable set of packages.',
            'Problem 1',
            '- vendor/first 1.0.0 requires ext-first * -> it is missing from your system.',
            'Problem 2',
            '- vendor/second 2.0.0 requires ext-second ^2.0 -> it is missing from your system.',
        ]);
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario([new UpgradeTarget('vendor/package', '^2.0')]), 2, '', $output, null, null, ScenarioResult::FAILURE_SOLVER),
        ], $evidence);

        self::assertCount(2, $blockers);
        self::assertSame(['ext-first', 'ext-second'], array_map(static fn (Blocker $blocker): string => $blocker->subject(), $blockers));
        self::assertSame(['*', '^2.0'], array_map(static fn (Blocker $blocker): ?string => $blocker->conflict(), $blockers));
        self::assertSame(['solver-1'], $blockers[0]->evidence());
        self::assertSame(['solver-1'], $blockers[1]->evidence());
        self::assertCount(1, $evidence->all());
    }

    public function testItDeduplicatesRootConflictsAcrossScenariosAndRetainsTheirEvidence(): void
    {
        $targets = [new UpgradeTarget('vendor/package', '^2.0')];
        $output = '- Root composer.json requires vendor/package ^1.0';
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario($targets, 'exact-target'), 2, '', $output, null, null, ScenarioResult::FAILURE_SOLVER),
            new ScenarioResult($this->scenario($targets, 'all-dependencies'), 2, '', $output, null, null, ScenarioResult::FAILURE_SOLVER),
        ], $evidence);

        self::assertCount(1, $blockers);
        self::assertSame('root-constraint-conflict', $blockers[0]->type());
        self::assertSame(['solver-1', 'solver-2'], $blockers[0]->evidence());
        $evidence->validateReferences($blockers[0]->evidence());
        self::assertSame(
            ['exact-target', 'all-dependencies'],
            array_map(static fn (Evidence $item): string => $item->context()['scenario'], $evidence->all())
        );
    }

    public function testItDeduplicatesEquivalentNonRootBlockersAcrossScenariosAndRetainsTheirEvidence(): void
    {
        $targets = [new UpgradeTarget('vendor/package', '^2.0')];
        $output = 'Root composer.json requires vendor/package ^2.0, found vendor/package[1.0.0] but it does not match the constraint.';
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario($targets, 'exact-target'), 2, '', $output, null, null, ScenarioResult::FAILURE_SOLVER),
            new ScenarioResult($this->scenario($targets, 'all-dependencies'), 2, '', $output, null, null, ScenarioResult::FAILURE_SOLVER),
        ], $evidence);

        self::assertCount(1, $blockers);
        self::assertSame('package-not-found', $blockers[0]->type());
        self::assertSame(['solver-1', 'solver-2'], $blockers[0]->evidence());
        $evidence->validateReferences($blockers[0]->evidence());
    }

    public function testItKeepsDifferentRootConflictsSeparate(): void
    {
        $targets = [new UpgradeTarget('vendor/package', '^2.0')];
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario($targets, 'exact-target'), 2, '', '- Root composer.json requires vendor/package ^1.0', null, null, ScenarioResult::FAILURE_SOLVER),
            new ScenarioResult($this->scenario($targets, 'all-dependencies'), 2, '', '- Root composer.json requires vendor/package ~1.5', null, null, ScenarioResult::FAILURE_SOLVER),
        ], $evidence);

        self::assertCount(2, $blockers);
        self::assertSame(['^1.0', '~1.5'], array_map(static fn (Blocker $blocker): ?string => $blocker->conflict(), $blockers));
        self::assertSame([['solver-1'], ['solver-2']], array_map(static fn (Blocker $blocker): array => $blocker->evidence(), $blockers));
    }

    public function testAnySuccessfulScenarioSuppressesFallbackBlockers(): void
    {
        $scenario = $this->scenario([new UpgradeTarget('vendor/package', '^2.0')]);
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($scenario, 2, '', 'vendor/package requires php >=8.2', null, null, ScenarioResult::FAILURE_SOLVER),
            new ScenarioResult($scenario, 0, 'Resolved.', '', new ComposerLock([])),
        ], $evidence);

        self::assertSame([], $blockers);
        self::assertSame([], $evidence->all());
    }

    public function testSuccessfulResolutionRetainsAbandonedPackagesFromCandidateLockMetadata(): void
    {
        $lock = new ComposerLock([
            'packages' => [[
                'name' => 'vendor/legacy',
                'version' => '1.2.3',
                'abandoned' => 'vendor/replacement',
            ]],
        ], ['vendor/legacy']);
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario([new UpgradeTarget('vendor/legacy', '^1.0')]), 0, 'Resolved.', '', $lock),
        ], $evidence, null, ['vendor/legacy' => '^1.0']);

        self::assertCount(1, $blockers);
        self::assertSame('abandoned-package', $blockers[0]->type());
        self::assertSame('vendor/legacy', $blockers[0]->subject());
        self::assertSame('^1.0', $blockers[0]->requestedConstraint());
        self::assertSame('1.2.3', $blockers[0]->lockedVersion());
        self::assertSame(['lock-metadata-1'], $blockers[0]->evidence());
        self::assertSame(Evidence::E2_PACKAGE_METADATA, $evidence->all()[0]->evidenceClass());
    }

    public function testLockMetadataTakesPrecedenceOverComposerProseAndRetainsBothEvidenceReferences(): void
    {
        $lock = new ComposerLock([
            'packages' => [[
                'name' => 'vendor/legacy',
                'version' => '1.2.3',
                'abandoned' => 'vendor/replacement',
            ]],
        ]);
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult(
                $this->scenario([new UpgradeTarget('vendor/legacy', '^2.0')]),
                2,
                '',
                'Package vendor/legacy is abandoned, you should avoid using it. Use vendor/replacement instead.',
                null,
                null,
                ScenarioResult::FAILURE_SOLVER
            ),
        ], $evidence, $lock, ['vendor/legacy' => '^2.0']);

        self::assertCount(1, $blockers);
        self::assertSame('1.2.3', $blockers[0]->lockedVersion());
        self::assertSame(['Replace `vendor/legacy` with `vendor/replacement`.'], $blockers[0]->options());
        self::assertSame(['lock-metadata-1', 'solver-1'], $blockers[0]->evidence());
        self::assertSame(
            [Evidence::E2_PACKAGE_METADATA, Evidence::E1_SOLVER],
            array_map(static fn (Evidence $item): string => $item->evidenceClass(), $evidence->all())
        );
        $evidence->validateReferences($blockers[0]->evidence());
    }

    public function testOperationalFailuresDoNotBecomeDependencyBlockers(): void
    {
        $evidence = new EvidenceLedger();
        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario([new UpgradeTarget('vendor/package', '^2.0')]), 1, '', 'Composer executable unavailable.', null, null, ScenarioResult::FAILURE_OPERATIONAL),
        ], $evidence);

        self::assertSame([], $blockers);
        self::assertSame([], $evidence->all());
    }

    public function testPresenceOnlyExtensionVersionConflictRemainsANonBlockingAdvisory(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('vendor/package', '^2.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [ExtensionAssumption::fromPresenceInput('ext-fixture')]
        );
        $project = new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([]));
        $platform = TargetPlatform::fromRequest($request, $project, []);
        $evidence = new EvidenceLedger();
        $output = 'vendor/package 2.0.0 requires ext-fixture >=1 -> your ext-fixture version (0; overridden via config.platform) does not satisfy that requirement.';

        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario([new UpgradeTarget('vendor/package', '^2.0')]), 2, '', $output, null, null, ScenarioResult::FAILURE_SOLVER),
        ], $evidence, null, [], $platform);

        self::assertCount(1, $blockers);
        self::assertSame('extension-version-unknown', $blockers[0]->type());
        self::assertSame('ext-fixture', $blockers[0]->subject());
        self::assertSame('>=1', $blockers[0]->conflict());
        self::assertSame('medium', $blockers[0]->confidence());
        self::assertFalse($blockers[0]->blocksResolution());
        self::assertStringContainsString('exact version', implode(' ', $blockers[0]->options()));
    }

    public function testExactExtensionVersionConflictRemainsAResolutionBlocker(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('vendor/package', '^2.0')],
            null,
            null,
            [],
            [],
            'json',
            null,
            false,
            [ExtensionAssumption::fromPresenceInput('ext-fixture:0')]
        );
        $project = new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([]));
        $platform = TargetPlatform::fromRequest($request, $project, []);
        $evidence = new EvidenceLedger();
        $output = 'vendor/package 2.0.0 requires ext-fixture >=1 -> your ext-fixture version (0; overridden via config.platform) does not satisfy that requirement.';

        $blockers = (new BlockerGrouper())->group([
            new ScenarioResult($this->scenario([new UpgradeTarget('vendor/package', '^2.0')]), 2, '', $output, null, null, ScenarioResult::FAILURE_SOLVER),
        ], $evidence, null, [], $platform);

        self::assertCount(1, $blockers);
        self::assertSame('extension-version-incompatible', $blockers[0]->type());
        self::assertTrue($blockers[0]->blocksResolution());
        self::assertStringContainsString('modeled', $blockers[0]->summary());
    }

    /** @param list<UpgradeTarget> $targets */
    private function scenario(array $targets, string $name = 'test'): Scenario
    {
        return new Scenario($name, new UpgradeTargetSet($targets));
    }
}
