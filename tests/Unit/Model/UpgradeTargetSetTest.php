<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use InvalidArgumentException;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Model\UpgradeTargetSet;
use PHPUnit\Framework\TestCase;

final class UpgradeTargetSetTest extends TestCase
{
    public function testItNormalizesDeduplicatesAndSortsTargets(): void
    {
        $targets = new UpgradeTargetSet([
            new UpgradeTarget(' Vendor/Package ', ' ^2.0 '),
            new UpgradeTarget('PHP', 'v8.1'),
            new UpgradeTarget('alpha/package', '^1.0'),
            new UpgradeTarget('vendor/package', '^2.0'),
        ], '8.1.0');

        self::assertSame('8.1.0', $targets->targetPhp());
        self::assertSame([
            ['package' => 'alpha/package', 'constraint' => '^1.0'],
            ['package' => 'php', 'constraint' => '8.1.0'],
            ['package' => 'vendor/package', 'constraint' => '^2.0'],
        ], $targets->toArray());
        self::assertSame(
            ['alpha/package', 'vendor/package'],
            array_map(static fn (UpgradeTarget $target): string => $target->package, $targets->packageTargets())
        );
        self::assertCount(3, $targets);
    }

    public function testItNormalizesPartialPhpVersions(): void
    {
        self::assertSame('8.0.0', (new UpgradeTargetSet([], '8'))->targetPhp());
        self::assertSame('8.2.0', (new UpgradeTargetSet([new UpgradeTarget('php', '8.2')]))->targetPhp());
        self::assertSame('8.2.3', (new UpgradeTargetSet([], '8.2.3'))->targetPhp());
    }

    public function testItDoesNotExposeMutableTargetState(): void
    {
        $input = new UpgradeTarget('vendor/package', '^1.0');
        $targets = new UpgradeTargetSet([$input]);

        $input->constraint = '^2.0';
        $returned = $targets->packageTargets();
        $returned[0]->constraint = '^3.0';

        self::assertSame([
            ['package' => 'vendor/package', 'constraint' => '^1.0'],
        ], $targets->toArray());
    }

    public function testItRejectsConflictingPackageDuplicates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting constraints for target "vendor/package"');

        new UpgradeTargetSet([
            new UpgradeTarget('vendor/package', '^1.0'),
            new UpgradeTarget('VENDOR/PACKAGE', '^2.0'),
        ]);
    }

    public function testItRejectsConflictingPhpTargets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting PHP targets');

        new UpgradeTargetSet([new UpgradeTarget('php', '8.1')], '8.2');
    }

    /**
     * @param list<mixed> $targets
     * @dataProvider invalidTargetProvider
     */
    public function testItRejectsInvalidTargets(array $targets, ?string $targetPhp, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new UpgradeTargetSet($targets, $targetPhp);
    }

    /** @return list<array{list<mixed>, ?string, string}> */
    public function invalidTargetProvider(): array
    {
        return [
            [[], null, 'At least one upgrade target is required.'],
            [['not-a-target'], null, 'must be an UpgradeTarget'],
            [[new UpgradeTarget('invalid', '^1.0')], null, 'Invalid Composer target package'],
            [[new UpgradeTarget('vendor/package', '')], null, 'non-empty constraint'],
            [[new UpgradeTarget('vendor/package', 'not a constraint')], null, 'Invalid constraint'],
            [[], '^8.1', 'must be an exact'],
        ];
    }

    public function testIterationUsesTheCanonicalOrder(): void
    {
        $targets = new UpgradeTargetSet([
            new UpgradeTarget('zeta/package', '^1.0'),
            new UpgradeTarget('alpha/package', '^1.0'),
        ], '8.3');

        self::assertSame(
            ['alpha/package', 'php', 'zeta/package'],
            array_map(static fn (UpgradeTarget $target): string => $target->package, iterator_to_array($targets))
        );
    }
}
