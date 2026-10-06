<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\SourceImpactReasonWriter;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Source\SymbolOwnershipIndex;
use PHPUnit\Framework\TestCase;

final class SourceImpactReasonWriterTest extends TestCase
{
    public function testFrameworkOnlyFindingsWithoutOwnershipStateThatOwnershipIsUnknown(): void
    {
        $reason = (new SourceImpactReasonWriter())->write(
            ['laravel', 'symfony'],
            null,
            null,
            $this->ownership([]),
            new SymbolOwnershipIndex()
        );

        self::assertSame(
            'Referenced by active laravel, symfony compatibility guidance; package ownership has not been established.',
            $reason
        );
    }

    public function testAPackageChangeWithoutOwnershipReportsBothTheChangeAndTheOwnershipGap(): void
    {
        $reason = (new SourceImpactReasonWriter())->write(
            [],
            new PackageChange('vendor/package', 'upgraded', '1.0.0', '2.0.0', true),
            null,
            $this->ownership([]),
            null
        );

        self::assertSame(
            'The symbol is owned by vendor/package, which is upgraded across a major version.'
            . ' Package ownership could not be established from supported Composer autoload metadata.',
            $reason
        );
    }

    public function testAPackageChangeAndFrameworkGuidanceAreBothCited(): void
    {
        $index = new SymbolOwnershipIndex();
        $reason = (new SourceImpactReasonWriter())->write(
            ['laravel'],
            new PackageChange('vendor/package', 'changed', '1.0.0', '1.1.0'),
            'vendor/package',
            $this->ownership(['vendor/package']),
            $index
        );

        self::assertSame(
            'The symbol is owned by vendor/package, which is changed.'
            . ' The usage is referenced by active laravel compatibility guidance.',
            $reason
        );
    }

    public function testAmbiguousOwnershipNamesEveryCandidate(): void
    {
        $index = new SymbolOwnershipIndex('acme/app');
        $reason = (new SourceImpactReasonWriter())->write(
            [],
            null,
            null,
            $this->ownership([SymbolOwnershipIndex::ROOT_OWNER, 'vendor/package']),
            $index
        );

        self::assertSame('Ownership is ambiguous between acme/app, vendor/package.', $reason);
    }

    public function testAmbiguousOwnershipFallsBackToRawCandidatesWithoutAnIndex(): void
    {
        $reason = (new SourceImpactReasonWriter())->write(
            [],
            null,
            null,
            $this->ownership(['vendor/one', 'vendor/two']),
            null
        );

        self::assertSame('Ownership is ambiguous between vendor/one, vendor/two.', $reason);
    }

    public function testAnUnchangedButOwnedSymbolNamesItsAutoloadOwner(): void
    {
        $index = new SymbolOwnershipIndex();
        $reason = (new SourceImpactReasonWriter())->write(
            ['laravel'],
            null,
            'vendor/package',
            $this->ownership(['vendor/package']),
            $index
        );

        self::assertSame(
            'The usage is referenced by active laravel compatibility guidance.'
            . ' Composer autoload metadata assigns the symbol to vendor/package.',
            $reason
        );
    }

    /**
     * @param list<string> $owners
     * @return array{owners: list<string>, mapping_types: list<string>, matched_prefix: ?string}
     */
    private function ownership(array $owners): array
    {
        return [
            'owners' => $owners,
            'mapping_types' => $owners === [] ? [] : ['psr-4'],
            'matched_prefix' => null,
        ];
    }
}
