<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\BlockerType;
use PhpUpgradePreflight\Core\Model\SolverRelation;
use PHPUnit\Framework\TestCase;

/**
 * Pins two vocabularies whose VALUES — not just their constant names — are load-bearing.
 *
 * Both are otherwise unguarded. `SolverRelation`'s values must equal the literal words Composer
 * prints in `prohibits --tree` output, because the parser assigns them from a lowercased regex
 * capture of that raw text. `BlockerType`'s values are serialized into `blockers[].type`, which the
 * report schema types as an open string with no enum.
 *
 * The danger is silence: every other test for these types is keyed BY the constants, so renaming a
 * value keeps those tests green while the parser stops matching real Composer output, or while a
 * published report changes shape. The expectations below are therefore declared independently, as
 * literals, and must be changed deliberately alongside a schema or parser decision.
 */
final class BlockerVocabularyTest extends TestCase
{
    public function testSolverRelationValuesMatchTheWordsComposerActuallyPrints(): void
    {
        self::assertSame('requires', SolverRelation::REQUIRES);
        self::assertSame('conflicts with', SolverRelation::CONFLICTS_WITH);
        self::assertSame('replaces', SolverRelation::REPLACES);
        self::assertSame('provides', SolverRelation::PROVIDES);
    }

    /**
     * The parser lowercases its capture before comparing, so an uppercase character in one of these
     * values could never match and would disable that relation's detection silently.
     */
    public function testSolverRelationValuesAreLowercaseSoTheParserCanMatchThem(): void
    {
        foreach ([SolverRelation::REQUIRES, SolverRelation::CONFLICTS_WITH, SolverRelation::REPLACES, SolverRelation::PROVIDES] as $relation) {
            self::assertSame(strtolower($relation), $relation);
        }
    }

    public function testBlockerTypeValuesAreTheSerializedVocabulary(): void
    {
        $expected = [
            'php-platform-too-low',
            'php-platform-too-high',
            'root-constraint-conflict',
            'transitive-package-conflict',
            'extension-missing',
            'extension-version-incompatible',
            'extension-version-unknown',
            'package-not-found',
            'minimum-stability-conflict',
            'replace-provide-conflict',
            'unknown-composer-failure',
            'abandoned-package',
        ];

        $actual = BlockerType::supportedTypes();
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * Only these two types are advisory. Every other type — including one this analyzer does not
     * recognize at all — must block resolution, because under-reporting a blocker is the more
     * dangerous direction for a preflight tool.
     */
    public function testOnlyTheTwoAdvisoryTypesAreNonBlockingAndUnknownTypesStillBlock(): void
    {
        $advisory = [];
        foreach (BlockerType::supportedTypes() as $type) {
            if (!BlockerType::blocksResolutionFor($type)) {
                $advisory[] = $type;
            }
        }
        sort($advisory);

        self::assertSame(['abandoned-package', 'extension-version-unknown'], $advisory);
        self::assertTrue(BlockerType::blocksResolutionFor('a-type-this-build-has-never-heard-of'));
    }
}
