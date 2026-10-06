<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PHPUnit\Framework\TestCase;

final class EffortEstimateTest extends TestCase
{
    public function testItKeepsAValidEstimateVerbatim(): void
    {
        $estimate = new EffortEstimate([4, 13], 'low', ['source_changes' => [1, 3]], ['The test suite is representative.']);

        self::assertSame([4, 13], $estimate->rangeHours());
        self::assertSame('low', $estimate->confidence());
        self::assertSame(['source_changes' => [1, 3]], $estimate->components());
        self::assertSame(['The test suite is representative.'], $estimate->assumptions());
        self::assertSame([4, 13], $estimate->toArray()['range_hours']);
    }

    public function testEmptyComponentsSerializeAsAFreshObjectPerReport(): void
    {
        /** @var \stdClass $first */
        $first = (new EffortEstimate([0, 0], 'low', [], []))->toArray()['components'];
        /** @var \stdClass $second */
        $second = (new EffortEstimate([0, 0], 'low', [], []))->toArray()['components'];

        self::assertNotSame($first, $second);

        $first->leaked = true;

        self::assertSame(['leaked' => true], get_object_vars($first));
        self::assertSame([], get_object_vars($second));
    }

    /**
     * @dataProvider invalidRangeProvider
     * @param array<mixed> $range
     */
    public function testItRejectsRangesThatAreNotAnOrderedNonNegativeIntegerPair(array $range, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new EffortEstimate($range, 'low', [], []);
    }

    /** @return array<string, array{array<mixed>, string}> */
    public function invalidRangeProvider(): array
    {
        return [
            'no bounds' => [[], 'Effort range must contain exactly a minimum and a maximum.'],
            'one bound' => [[3], 'Effort range must contain exactly a minimum and a maximum.'],
            'three bounds' => [[1, 2, 3], 'Effort range must contain exactly a minimum and a maximum.'],
            'named bounds' => [['minimum' => 1, 'maximum' => 2], 'Effort range must contain exactly a minimum and a maximum.'],
            'string bound' => [[1, '4'], 'Effort range bounds must be integer hours.'],
            'float bound' => [[1, 4.5], 'Effort range bounds must be integer hours.'],
            'negative bound' => [[-1, 4], 'Effort range bounds cannot be negative.'],
            'inverted bounds' => [[8, 3], 'Effort range minimum cannot exceed its maximum.'],
        ];
    }

    /**
     * @dataProvider invalidComponentProvider
     * @param array<mixed> $components
     */
    public function testItAppliesTheSameRangeInvariantToEveryComponent(array $components, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new EffortEstimate([0, 0], 'low', $components, []);
    }

    /** @return array<string, array{array<mixed>, string}> */
    public function invalidComponentProvider(): array
    {
        return [
            'scalar component' => [['source_changes' => 9], 'Effort component "source_changes" must be an hour range.'],
            'inverted component' => [['source_changes' => [9, 2]], 'Effort component "source_changes" minimum cannot exceed its maximum.'],
            'negative component' => [['source_changes' => [-1, 2]], 'Effort component "source_changes" bounds cannot be negative.'],
            'partial component' => [['source_changes' => [9]], 'Effort component "source_changes" must contain exactly a minimum and a maximum.'],
            'non-integer component' => [['source_changes' => [1, '2']], 'Effort component "source_changes" bounds must be integer hours.'],
            'numeric component name' => [[7 => [1, 2]], 'Effort component names must be non-empty strings.'],
        ];
    }

    public function testItRejectsAConfidenceOutsideTheCanonicalScale(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported effort confidence "definitely".');

        new EffortEstimate([0, 0], 'definitely', [], []);
    }

    public function testItRejectsAssumptionsThatCannotBeSerialized(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Effort assumptions must be strings.');

        new EffortEstimate([0, 0], 'low', [], [42]); // @phpstan-ignore argument.type
    }
}
