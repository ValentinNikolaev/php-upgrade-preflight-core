<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\SolverRelation;
use PHPUnit\Framework\TestCase;

final class SolverRelationTest extends TestCase
{
    public function testItExposesEveryFieldParsedFromComposerOutput(): void
    {
        $relation = new SolverRelation(
            'vendor/blocker',
            '1.0.0',
            SolverRelation::REQUIRES,
            'vendor/target',
            '^1.0'
        );

        self::assertSame('vendor/blocker', $relation->package());
        self::assertSame('1.0.0', $relation->version());
        self::assertSame(SolverRelation::REQUIRES, $relation->operation());
        self::assertSame('vendor/target', $relation->dependency());
        self::assertSame('^1.0', $relation->constraint());
    }

    public function testAnUnresolvedVersionAndConstraintStayNull(): void
    {
        $relation = new SolverRelation('vendor/blocker', null, SolverRelation::REQUIRES, 'php', null);

        self::assertNull($relation->version());
        self::assertNull($relation->constraint());
    }

    /** @dataProvider operationProvider */
    public function testOnlyReplaceProvideAndConflictRulesAreIncompatibilities(
        string $operation,
        bool $expected
    ): void {
        $relation = new SolverRelation('vendor/blocker', '1.0.0', $operation, 'vendor/target', '^1.0');

        self::assertSame($expected, $relation->isIncompatibilityRule());
    }

    /** @return array<string, array{string, bool}> */
    public function operationProvider(): array
    {
        return [
            SolverRelation::REQUIRES => [SolverRelation::REQUIRES, false],
            SolverRelation::REPLACES => [SolverRelation::REPLACES, true],
            SolverRelation::PROVIDES => [SolverRelation::PROVIDES, true],
            SolverRelation::CONFLICTS_WITH => [SolverRelation::CONFLICTS_WITH, true],
        ];
    }
}
