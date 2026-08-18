<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\EvidenceRecorder;
use PHPUnit\Framework\TestCase;

final class EvidenceLedgerTest extends TestCase
{
    public function testItAllocatesUniqueDeterministicIdsWithinNamespaces(): void
    {
        $ledger = new EvidenceLedger();

        $firstSolver = $ledger->add('solver', Evidence::E1_SOLVER, 'First solver result.');
        $source = $ledger->add('source', Evidence::E3_PROJECT_SOURCE, 'Source match.');
        $secondSolver = $ledger->add('solver', Evidence::E1_SOLVER, 'Second solver result.');

        self::assertSame('solver-1', $firstSolver->id());
        self::assertSame('source-1', $source->id());
        self::assertSame('solver-2', $secondSolver->id());
        self::assertSame([$firstSolver, $source, $secondSolver], $ledger->all());
    }

    public function testItSkipsARegisteredIdWhenAllocating(): void
    {
        $ledger = new EvidenceLedger([
            new Evidence('solver-1', Evidence::E1_SOLVER, 'Imported result.'),
        ]);

        self::assertSame('solver-2', $ledger->add('solver', Evidence::E1_SOLVER, 'New result.')->id());
    }

    public function testItRejectsDuplicateRegisteredIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        new EvidenceLedger([
            new Evidence('solver-1', Evidence::E1_SOLVER, 'First result.'),
            new Evidence('solver-1', Evidence::E1_SOLVER, 'Duplicate result.'),
        ]);
    }

    public function testItRejectsReferencesMissingFromTheLedger(): void
    {
        $ledger = new EvidenceLedger();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing-1');

        $ledger->validateReferences(['missing-1']);
    }

    public function testItRejectsOrphanedEvidence(): void
    {
        $ledger = new EvidenceLedger();
        $ledger->add('solver', Evidence::E1_SOLVER, 'Solver result.');
        $ledger->add('source', Evidence::E3_PROJECT_SOURCE, 'Source match.');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('source-1');

        $ledger->validateReferences(['solver-1']);
    }

    public function testItAcceptsSharedReferencesWithoutDuplicatingEvidence(): void
    {
        $ledger = new EvidenceLedger();
        $ledger->add('solver', Evidence::E1_SOLVER, 'Solver result.');

        $ledger->validateReferences(['solver-1', 'solver-1']);

        self::assertCount(1, $ledger->all());
    }

    public function testAddOnceReusesTheFirstIdenticalRecordInANamespace(): void
    {
        $ledger = new EvidenceLedger();
        $first = $ledger->addOnce('source', Evidence::E3_PROJECT_SOURCE, 'Detected usage.', 'high', ['file' => 'src/A.php']);
        $second = $ledger->addOnce('source', Evidence::E3_PROJECT_SOURCE, 'Detected usage.', 'high', ['file' => 'src/A.php']);

        self::assertSame($first, $second);
        self::assertSame('source-1', $first->id());
        self::assertCount(1, $ledger->all());
    }

    public function testAddOnceSeparatesRecordsThatDifferInAnyComparedField(): void
    {
        $ledger = new EvidenceLedger();
        $records = [
            $ledger->addOnce('source', Evidence::E3_PROJECT_SOURCE, 'Detected usage.', 'high', ['file' => 'src/A.php']),
            $ledger->addOnce('source', Evidence::E2_PACKAGE_METADATA, 'Detected usage.', 'high', ['file' => 'src/A.php']),
            $ledger->addOnce('source', Evidence::E3_PROJECT_SOURCE, 'Detected other usage.', 'high', ['file' => 'src/A.php']),
            $ledger->addOnce('source', Evidence::E3_PROJECT_SOURCE, 'Detected usage.', 'medium', ['file' => 'src/A.php']),
            $ledger->addOnce('source', Evidence::E3_PROJECT_SOURCE, 'Detected usage.', 'high', ['file' => 'src/B.php']),
            $ledger->addOnce('lock', Evidence::E3_PROJECT_SOURCE, 'Detected usage.', 'high', ['file' => 'src/A.php']),
        ];

        self::assertSame(
            ['source-1', 'source-2', 'source-3', 'source-4', 'source-5', 'lock-1'],
            array_map(static fn (Evidence $evidence): string => $evidence->id(), $records)
        );
        self::assertSame($records, $ledger->all());
    }

    public function testAddOnceTreatsReorderedContextKeysAsDistinctEvidence(): void
    {
        $ledger = new EvidenceLedger();
        $ordered = $ledger->addOnce('source', Evidence::E3_PROJECT_SOURCE, 'Ordered context.', 'high', ['a' => 1, 'b' => 2]);
        $swapped = $ledger->addOnce('source', Evidence::E3_PROJECT_SOURCE, 'Ordered context.', 'high', ['b' => 2, 'a' => 1]);

        self::assertNotSame($ordered, $swapped);
        self::assertSame(['source-1', 'source-2'], [$ordered->id(), $swapped->id()]);
    }

    public function testAddOnceMatchesRegisteredEvidenceByIdPrefix(): void
    {
        $ledger = new EvidenceLedger([
            new Evidence('source-kernel-1', Evidence::E3_PROJECT_SOURCE, 'Kernel middleware.'),
        ]);

        $reused = $ledger->addOnce('source', Evidence::E3_PROJECT_SOURCE, 'Kernel middleware.');

        self::assertSame('source-kernel-1', $reused->id());
        self::assertCount(1, $ledger->all());
    }

    public function testAddOnceComparesTheStoredRedactedSummaryNotTheRawInput(): void
    {
        $ledger = new EvidenceLedger();
        $summary = 'Authorization: Bearer abcdef1234567890';
        $first = $ledger->addOnce('solver', Evidence::E1_SOLVER, $summary);
        $second = $ledger->addOnce('solver', Evidence::E1_SOLVER, $summary);
        $redacted = $ledger->addOnce('solver', Evidence::E1_SOLVER, $first->summary());

        self::assertNotSame($summary, $first->summary());
        self::assertNotSame($first, $second);
        self::assertSame($first, $redacted);
        self::assertSame(['solver-1', 'solver-2'], array_map(
            static fn (Evidence $evidence): string => $evidence->id(),
            $ledger->all()
        ));
    }

    public function testTheLedgerRecordsThroughTheNarrowRecorderContract(): void
    {
        $ledger = new EvidenceLedger();

        self::assertSame('solver-1', $this->recordOnce($ledger, 'First result.')->id());
        self::assertSame('solver-1', $this->recordOnce($ledger, 'First result.')->id());
        self::assertSame('solver-2', $this->recordOnce($ledger, 'Second result.')->id());
    }

    private function recordOnce(EvidenceRecorder $recorder, string $summary): Evidence
    {
        return $recorder->addOnce('solver', Evidence::E1_SOLVER, $summary);
    }
}
