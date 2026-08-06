<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
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
}
