<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\BlockerType;
use PhpUpgradePreflight\Core\Model\StageBlockerEntry;
use PHPUnit\Framework\TestCase;

final class StageBlockerEntryTest extends TestCase
{
    public function testItProjectsTheBlockerItWrapsInAStableCanonicalOrder(): void
    {
        $entry = StageBlockerEntry::detected(
            'fixture-1-to-2',
            1,
            'attempt-1',
            $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-1'),
            ['attempt-1']
        );
        $canonical = $entry->toArray();

        self::assertSame([
            'id',
            'stage_id',
            'attempt',
            'scenario',
            'category',
            'subject',
            'blocking_package',
            'requested_constraint',
            'constraint',
            'dependency_path',
            'confidence',
            'summary',
            'options',
            'blocking',
            'evidence',
            'first_seen',
            'last_seen',
            'lifecycle',
            'lifecycle_history',
            'observations',
        ], array_keys($canonical));

        self::assertSame(BlockerType::TRANSITIVE_PACKAGE_CONFLICT, $canonical['category']);
        self::assertSame('vendor/target', $canonical['subject']);
        self::assertSame('vendor/blocker', $canonical['blocking_package']);
        self::assertSame('^2.0', $canonical['requested_constraint']);
        self::assertSame('^1.0', $canonical['constraint']);
        self::assertSame(['root/project', 'vendor/blocker', 'vendor/target'], $canonical['dependency_path']);
        self::assertSame('high', $canonical['confidence']);
        self::assertSame('A transitive constraint blocks the target.', $canonical['summary']);
        self::assertSame(['Upgrade vendor/blocker.'], $canonical['options']);
        self::assertTrue($canonical['blocking']);
        self::assertSame('A transitive constraint blocks the target.', $entry->summary());
        self::assertSame(['Upgrade vendor/blocker.'], $entry->options());
        self::assertTrue($entry->isBlocking());
    }

    public function testAnAdvisoryBlockerIsRegisteredWithoutBlockingTheStage(): void
    {
        $entry = StageBlockerEntry::detected(
            'fixture-1-to-2',
            1,
            'attempt-1',
            new Blocker(
                BlockerType::ABANDONED_PACKAGE,
                'vendor/legacy',
                'Composer lock metadata marks this package as abandoned.',
                'high',
                ['lock-metadata-1'],
                null,
                null,
                '1.0.0',
                null,
                ['vendor/legacy'],
                ['Replace or remove `vendor/legacy`.']
            )
        );

        self::assertFalse($entry->isBlocking());
        self::assertFalse($entry->toArray()['blocking']);
        self::assertSame(BlockerType::ABANDONED_PACKAGE, $entry->toArray()['category']);
    }

    public function testItRetainsDetectedPersistentAndResolvedLifecycleHistory(): void
    {
        $blocker = $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-1');
        $detected = StageBlockerEntry::detected('fixture-1-to-2', 1, 'attempt-1', $blocker, ['attempt-1']);
        $persistent = $detected->withObservation(
            2,
            'attempt-2',
            $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-2')
        );
        $resolved = $persistent->withLifecycle(StageBlockerEntry::RESOLVED, 3, 'attempt-3', ['attempt-3']);

        self::assertSame(StageBlockerEntry::DETECTED, $detected->lifecycle());
        self::assertSame(StageBlockerEntry::PERSISTS, $persistent->lifecycle());
        self::assertSame(StageBlockerEntry::RESOLVED, $resolved->lifecycle());
        self::assertFalse($resolved->isActive());
        self::assertSame(
            [StageBlockerEntry::DETECTED, StageBlockerEntry::PERSISTS, StageBlockerEntry::RESOLVED],
            array_column($resolved->toArray()['lifecycle_history'], 'status')
        );
        self::assertSame(['solver-1', 'attempt-1', 'solver-2', 'attempt-3'], $resolved->evidence());
        self::assertSame(3, $resolved->toArray()['last_seen']['attempt']);
    }

    public function testItRecordsSupersessionAsASeparateTerminalLifecycle(): void
    {
        $entry = StageBlockerEntry::detected(
            'fixture-1-to-2',
            1,
            'attempt-1',
            $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-1')
        );
        $superseded = $entry->withLifecycle(StageBlockerEntry::SUPERSEDED, 2, 'attempt-2', ['attempt-2']);

        self::assertSame(StageBlockerEntry::SUPERSEDED, $superseded->lifecycle());
        self::assertFalse($superseded->isActive());
        self::assertSame(
            [StageBlockerEntry::DETECTED, StageBlockerEntry::SUPERSEDED],
            array_column($superseded->toArray()['lifecycle_history'], 'status')
        );
    }

    public function testItReopensTheSameEntryWithoutDiscardingTerminalHistory(): void
    {
        $detected = StageBlockerEntry::detected(
            'fixture-1-to-2',
            1,
            'attempt-1',
            $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-1')
        );
        $resolved = $detected->withLifecycle(
            StageBlockerEntry::RESOLVED,
            2,
            'attempt-2',
            ['attempt-2']
        );
        $reappeared = $resolved->withReappearance(
            3,
            'attempt-3',
            $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-3')
        );

        self::assertSame($detected->id(), $reappeared->id());
        self::assertTrue($reappeared->isActive());
        self::assertSame(StageBlockerEntry::DETECTED, $reappeared->lifecycle());
        self::assertSame(
            [StageBlockerEntry::DETECTED, StageBlockerEntry::RESOLVED, StageBlockerEntry::DETECTED],
            array_column($reappeared->toArray()['lifecycle_history'], 'status')
        );
        self::assertSame([1, 3], array_column($reappeared->toArray()['observations'], 'attempt'));
        self::assertSame(1, $reappeared->toArray()['first_seen']['attempt']);
        self::assertSame(3, $reappeared->toArray()['last_seen']['attempt']);
        self::assertSame(['solver-1', 'attempt-2', 'solver-3'], $reappeared->evidence());
    }

    public function testIdentitySeparatesStagesConstraintsAndDependencyPaths(): void
    {
        $base = StageBlockerEntry::detected(
            'fixture-1-to-2',
            1,
            'attempt-1',
            $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-1')
        );
        $same = StageBlockerEntry::detected(
            'fixture-1-to-2',
            2,
            'attempt-2',
            $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-2')
        );
        $newConstraint = StageBlockerEntry::detected(
            'fixture-1-to-2',
            2,
            'attempt-2',
            $this->blocker('^2.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-2')
        );
        $newPath = StageBlockerEntry::detected(
            'fixture-1-to-2',
            2,
            'attempt-2',
            $this->blocker('^1.0', ['root/project', 'vendor/other', 'vendor/target'], 'solver-2')
        );
        $newStage = StageBlockerEntry::detected(
            'fixture-2-to-3',
            1,
            'attempt-1',
            $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-1')
        );
        $newLockedVersion = StageBlockerEntry::detected(
            'fixture-1-to-2',
            2,
            'attempt-2',
            $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-2', '1.1.0')
        );

        self::assertSame($base->identityKey(), $same->identityKey());
        self::assertSame($base->id(), $same->id());
        self::assertSame($base->identityKey(), $newLockedVersion->identityKey());
        self::assertSame($base->id(), $newLockedVersion->id());
        self::assertNotSame($base->identityKey(), $newConstraint->identityKey());
        self::assertNotSame($base->identityKey(), $newPath->identityKey());
        self::assertNotSame($base->identityKey(), $newStage->identityKey());
        self::assertSame($base->supersessionKey(), $newConstraint->supersessionKey());
    }

    public function testItRejectsANonTerminalDirectLifecycleTransition(): void
    {
        $entry = StageBlockerEntry::detected(
            'fixture-1-to-2',
            1,
            'attempt-1',
            $this->blocker('^1.0', ['root/project', 'vendor/blocker', 'vendor/target'], 'solver-1')
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only terminal blocker lifecycle transitions may be applied directly.');

        $entry->withLifecycle(StageBlockerEntry::PERSISTS, 2, 'attempt-2', []);
    }

    /** @param list<string> $path */
    private function blocker(
        string $constraint,
        array $path,
        string $evidence,
        string $lockedVersion = '1.0.0'
    ): Blocker {
        return new Blocker(
            'transitive-package-conflict',
            'vendor/target',
            'A transitive constraint blocks the target.',
            'high',
            [$evidence],
            '^2.0',
            'vendor/blocker',
            $lockedVersion,
            $constraint,
            $path,
            ['Upgrade vendor/blocker.']
        );
    }
}
