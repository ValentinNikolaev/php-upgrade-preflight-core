<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\StageBlockerRegistry;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\StageBlockerEntry;
use PHPUnit\Framework\TestCase;

final class StageBlockerRegistryTest extends TestCase
{
    public function testItKeepsFirstSeenOrderWhileFoldingRepeatedObservations(): void
    {
        $registry = new StageBlockerRegistry();
        $registry->observe('stage-a', 1, 'stage-a-attempt-1', [$this->blocker('ext-first')], 'evidence-1', false);
        $registry->observe(
            'stage-a',
            2,
            'stage-a-attempt-2',
            [$this->blocker('ext-second'), $this->blocker('ext-first')],
            'evidence-2',
            false
        );

        self::assertSame(['ext-first', 'ext-second'], $this->subjects($registry));
        self::assertSame(['persists', 'detected'], $this->lifecycles($registry));
        self::assertSame(['detected', 'persists'], $this->history($registry, 0));
    }

    public function testAnAttemptWithFeasibilityEvidenceResolvesUnobservedEntriesOfThatStage(): void
    {
        $registry = new StageBlockerRegistry();
        $registry->observe('stage-a', 1, 'stage-a-attempt-1', [$this->blocker('ext-first')], 'evidence-1', true);
        $registry->observe('stage-b', 1, 'stage-b-attempt-1', [$this->blocker('ext-other')], 'evidence-2', true);
        $registry->observe('stage-a', 2, 'stage-a-attempt-2', [], 'evidence-3', true);

        self::assertFalse($registry->hasActiveBlocking('stage-a'));
        self::assertTrue($registry->hasActiveBlocking('stage-b'));
        self::assertSame(['resolved', 'detected'], $this->lifecycles($registry));
    }

    public function testAResolvedBlockerThatReappearsReusesItsEntry(): void
    {
        $registry = new StageBlockerRegistry();
        $registry->observe('stage-a', 1, 'stage-a-attempt-1', [$this->blocker('ext-first')], 'evidence-1', true);
        $registry->observe('stage-a', 2, 'stage-a-attempt-2', [], 'evidence-3', true);
        $ids = $registry->observe(
            'stage-a',
            3,
            'stage-a-attempt-3',
            [$this->blocker('ext-first')],
            'evidence-4',
            true
        );
        $ordered = $registry->ordered();

        self::assertCount(1, $ordered);
        self::assertSame([$ordered[0]->id()], $ids);
        self::assertSame(['detected', 'resolved', 'detected'], $this->history($registry, 0));
        self::assertTrue($registry->hasActiveBlocking('stage-a'));
    }

    public function testAChangedConstraintSupersedesTheEarlierIdentityWithoutReordering(): void
    {
        $registry = new StageBlockerRegistry();
        $registry->observe(
            'stage-a',
            1,
            'stage-a-attempt-1',
            [$this->blocker('vendor/framework', '^0.0')],
            'evidence-1',
            false
        );
        $registry->observe(
            'stage-a',
            2,
            'stage-a-attempt-2',
            [$this->blocker('vendor/framework', '^0.5')],
            'evidence-2',
            false
        );

        self::assertSame(['superseded', 'detected'], $this->lifecycles($registry));
        self::assertTrue($registry->hasActiveBlocking('stage-a'));
    }

    public function testAnEmptyRegistryReportsNoBlockingEntries(): void
    {
        $registry = new StageBlockerRegistry();

        self::assertSame([], $registry->ordered());
        self::assertFalse($registry->hasActiveBlocking('stage-a'));
    }

    /** @return list<string> */
    private function lifecycles(StageBlockerRegistry $registry): array
    {
        return array_map(
            static fn (StageBlockerEntry $entry): string => $entry->lifecycle(),
            $registry->ordered()
        );
    }

    /** @return list<string> */
    private function subjects(StageBlockerRegistry $registry): array
    {
        return array_map(
            static fn (StageBlockerEntry $entry): string => (string) $entry->toArray()['subject'],
            $registry->ordered()
        );
    }

    /** @return array<int, mixed> */
    private function history(StageBlockerRegistry $registry, int $index): array
    {
        $entries = $registry->ordered();
        self::assertArrayHasKey($index, $entries);

        return array_column($entries[$index]->toArray()['lifecycle_history'], 'status');
    }

    private function blocker(string $subject, string $conflict = '^1.0'): Blocker
    {
        return new Blocker(
            'transitive-package-conflict',
            $subject,
            'A transitive package blocks the target.',
            'high',
            ['solver-evidence'],
            '^1.0',
            'vendor/blocker',
            '1.0.0',
            $conflict,
            ['vendor/blocker', $subject],
            ['Upgrade vendor/blocker.']
        );
    }
}
