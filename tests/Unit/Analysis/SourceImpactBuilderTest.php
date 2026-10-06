<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\SourceImpactBuilder;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Source\SymbolOwnershipIndex;
use PHPUnit\Framework\TestCase;

final class SourceImpactBuilderTest extends TestCase
{
    public function testItOnlyPromotesInventoryReferencedByFrameworkGuidance(): void
    {
        $related = new SourceUsage('app/Kernel.php', 'Legacy\\Middleware', 'middleware_reference', ['source-1'], 12);
        $unrelated = new SourceUsage('app/Service.php', 'App\\Value', 'import', ['source-2'], 8);

        $impact = (new SourceImpactBuilder())->build(
            [$related, $unrelated],
            [new CompatibilityFinding('laravel', 'high', 'Review the middleware.', ['source-1', 'docs-1'])]
        );

        self::assertCount(1, $impact);
        self::assertNull($impact[0]->affectedPackage());
        self::assertSame('unknown', $impact[0]->ownership());
        self::assertSame('framework_rule', $impact[0]->relevance());
        self::assertSame('high', $impact[0]->severity());
        self::assertSame([$related], $impact[0]->occurrences());
        self::assertSame(['source-1', 'docs-1'], $impact[0]->evidence());
        self::assertStringContainsString('package ownership has not been established', $impact[0]->reason());
    }

    public function testItCorrelatesExactOwnershipWithRelevantPackageChangesAndFrameworkRules(): void
    {
        $usage = new SourceUsage('app/Service.php', 'Vendor\\Package\\Client', 'instantiated_class', ['source-1'], 18);
        $framework = new CompatibilityFinding('fixture', 'medium', 'Review the client.', ['source-1', 'docs-1']);
        $change = new PackageChange('vendor/package', 'upgraded', '1.4.0', '2.0.0', true);
        $index = new SymbolOwnershipIndex('fixture/root');
        $index->addPrefix('Vendor\\Package\\', 'vendor/package', 'psr-4');
        $evidence = new EvidenceLedger([
            new \PhpUpgradePreflight\Core\Model\Evidence('source-1', 'E3', 'Usage.'),
            new \PhpUpgradePreflight\Core\Model\Evidence('docs-1', 'E4', 'Guidance.'),
        ]);

        $impact = (new SourceImpactBuilder())->build([$usage], [$framework], [$change], $index, $evidence);

        self::assertCount(1, $impact);
        self::assertSame('vendor/package', $impact[0]->affectedPackage());
        self::assertSame('exact', $impact[0]->ownership());
        self::assertSame('package_change_and_framework_rule', $impact[0]->relevance());
        self::assertSame('high', $impact[0]->severity());
        self::assertStringContainsString('upgraded across a major version', $impact[0]->reason());
        self::assertCount(3, $impact[0]->evidence());
        self::assertSame('ownership-1', $impact[0]->evidence()[2]);
    }

    public function testItIgnoresRawUsagesOwnedOnlyByAddedPackages(): void
    {
        $usage = new SourceUsage('app/Service.php', 'Vendor\\Package\\Client', 'instantiated_class', ['source-1'], 18);
        $index = new SymbolOwnershipIndex();
        $index->addPrefix('Vendor\\Package\\', 'vendor/package', 'psr-4');

        $impact = (new SourceImpactBuilder())->build(
            [$usage],
            [],
            [new PackageChange('vendor/package', 'added', null, '1.0.0')],
            $index
        );

        self::assertSame([], $impact);
    }

    public function testItTreatsSameVersionReferenceChangesAsRelevant(): void
    {
        $usage = new SourceUsage('app/Service.php', 'Vendor\\Package\\Client', 'instantiated_class', ['source-1'], 18);
        $index = new SymbolOwnershipIndex();
        $index->addPrefix('Vendor\\Package\\', 'vendor/package', 'psr-4');

        $impact = (new SourceImpactBuilder())->build(
            [$usage],
            [],
            [new PackageChange('vendor/package', 'changed', 'dev-main', 'dev-main', false, 'old-ref', 'new-ref')],
            $index
        );

        self::assertCount(1, $impact);
        self::assertSame('vendor/package', $impact[0]->affectedPackage());
        self::assertSame('package_change', $impact[0]->relevance());
        self::assertSame('medium', $impact[0]->severity());
        self::assertStringContainsString('which is changed', $impact[0]->reason());
    }

    public function testItRequiresExactOwnershipForFunctionAndConstantUsages(): void
    {
        $function = new SourceUsage('app/helpers.php', 'Vendor\\Package\\helper', 'function_call', ['source-1'], 8);
        $nonClassUsages = [
            $function,
            new SourceUsage('app/helpers.php', 'Vendor\\Package\\helper', 'function_import', ['source-2'], 5),
            new SourceUsage('app/helpers.php', 'Vendor\\Package\\FLAG', 'constant_access', ['source-3'], 9),
            new SourceUsage('app/helpers.php', 'Vendor\\Package\\FLAG', 'constant_import', ['source-4'], 6),
        ];
        $change = new PackageChange('vendor/package', 'upgraded', '1.0.0', '2.0.0');
        $index = new SymbolOwnershipIndex();
        $index->addPrefix('Vendor\\Package\\', 'vendor/package', 'psr-4');

        self::assertSame([], (new SourceImpactBuilder())->build($nonClassUsages, [], [$change], $index));

        $index->addExact('Vendor\\Package\\helper', 'vendor/package', 'files', 'function');
        $impact = (new SourceImpactBuilder())->build([$function], [], [$change], $index);

        self::assertCount(1, $impact);
        self::assertSame('vendor/package', $impact[0]->affectedPackage());
        self::assertSame('exact', $impact[0]->ownership());
    }

    public function testItUsesTypedCaseSensitiveExactOwnershipForConstants(): void
    {
        $change = new PackageChange('vendor/package', 'upgraded', '1.0.0', '2.0.0');
        $index = new SymbolOwnershipIndex();
        $index->addExact('Vendor\\Package\\FLAG', 'vendor/package', 'files', 'constant');
        $index->addExact('Vendor\\Package\\helper', 'vendor/package', 'files', 'function');

        $impact = (new SourceImpactBuilder())->build([
            new SourceUsage('app/example.php', 'Vendor\\Package\\FLAG', 'constant_access', ['source-1'], 8),
            new SourceUsage('app/example.php', 'Vendor\\Package\\flag', 'constant_access', ['source-2'], 9),
            new SourceUsage('app/example.php', 'Vendor\\Package\\FLAG', 'function_call', ['source-3'], 10),
        ], [], [$change], $index);

        self::assertCount(1, $impact);
        self::assertSame('Vendor\\Package\\FLAG', $impact[0]->occurrences()[0]->symbol());
        self::assertSame('constant_access', $impact[0]->occurrences()[0]->usageType());
    }

    public function testItReportsAmbiguousOwnershipForChangedPackageCandidates(): void
    {
        $usage = new SourceUsage('app/Service.php', 'Shared\\Client', 'instantiated_class', ['source-1'], 18);
        $index = new SymbolOwnershipIndex();
        $index->addPrefix('Shared\\', 'vendor/a', 'psr-4');
        $index->addPrefix('Shared\\', 'vendor/b', 'psr-4');

        $impact = (new SourceImpactBuilder())->build(
            [$usage],
            [],
            [new PackageChange('vendor/b', 'removed', '1.0.0', null)],
            $index
        );

        self::assertCount(1, $impact);
        self::assertSame('vendor/b', $impact[0]->affectedPackage());
        self::assertSame('ambiguous', $impact[0]->ownership());
        self::assertSame('package_change', $impact[0]->relevance());
        self::assertStringContainsString('vendor/a, vendor/b', $impact[0]->reason());
    }

    public function testItGroupsRepeatedUsagesButPreservesEveryExactOccurrenceAndEvidenceReference(): void
    {
        $inventory = [
            new SourceUsage('app/First.php', 'Vendor\\Package\\Client', 'static_call', ['source-1'], 10),
            new SourceUsage('app/First.php', 'Vendor\\Package\\Client', 'static_call', ['source-2'], 14),
            new SourceUsage('app/Second.php', 'Vendor\\Package\\Client', 'static_call', ['source-3'], 8),
            new SourceUsage('app/Second.php', 'Vendor\\Package\\Client', 'static_call', ['source-4'], 8),
        ];
        $index = new SymbolOwnershipIndex();
        $index->addPrefix('Vendor\\Package\\', 'vendor/package', 'psr-4');

        $impact = (new SourceImpactBuilder())->build(
            $inventory,
            [],
            [new PackageChange('vendor/package', 'upgraded', '1.0.0', '1.1.0')],
            $index
        );

        self::assertCount(1, $impact);
        self::assertSame(
            [
                ['app/First.php', 10, ['source-1']],
                ['app/First.php', 14, ['source-2']],
                ['app/Second.php', 8, ['source-3', 'source-4']],
            ],
            array_map(
                static fn (SourceUsage $usage): array => [$usage->file(), $usage->line(), $usage->evidence()],
                $impact[0]->occurrences()
            )
        );
        self::assertSame(['source-1', 'source-2', 'source-3', 'source-4'], $impact[0]->evidence());
        self::assertMatchesRegularExpression('/^source-impact-[a-f0-9]{20}$/', $impact[0]->id());

        $merged = $impact[0]->withStageIds(['fixture-1-to-2'])->merge(
            $impact[0]->withStageIds(['fixture-2-to-3'])
        );
        self::assertSame(['fixture-1-to-2', 'fixture-2-to-3'], $merged->stageIds());
        self::assertCount(3, $merged->occurrences());
    }
}
