<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class EffortEstimate
{
    /** @var array{0:int,1:int} */
    private array $rangeHours;
    private string $confidence;
    /** @var array<string, array{0:int,1:int}> */
    private array $components;
    /** @var list<string> */
    private array $assumptions;

    /**
     * @param array{0:int,1:int} $rangeHours
     * @param array<string, array{0:int,1:int}> $components
     * @param list<string> $assumptions
     */
    public function __construct(array $rangeHours, string $confidence, array $components, array $assumptions)
    {
        Confidence::assert($confidence, 'effort confidence');

        $normalizedComponents = [];
        foreach ($components as $name => $componentRange) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('Effort component names must be non-empty strings.');
            }
            if (!is_array($componentRange)) {
                throw new \InvalidArgumentException(sprintf('Effort component "%s" must be an hour range.', $name));
            }

            $normalizedComponents[$name] = self::normalizedRange(
                $componentRange,
                sprintf('Effort component "%s"', $name)
            );
        }

        foreach ($assumptions as $assumption) {
            if (!is_string($assumption)) {
                throw new \InvalidArgumentException('Effort assumptions must be strings.');
            }
        }

        $this->rangeHours = self::normalizedRange($rangeHours, 'Effort range');
        $this->confidence = $confidence;
        $this->components = $normalizedComponents;
        $this->assumptions = array_values($assumptions);
    }

    /** @return array{0:int,1:int} */
    public function rangeHours(): array
    {
        return $this->rangeHours;
    }

    public function confidence(): string
    {
        return $this->confidence;
    }

    /** @return array<string, array{0:int,1:int}> */
    public function components(): array
    {
        return $this->components;
    }

    /** @return list<string> */
    public function assumptions(): array
    {
        return $this->assumptions;
    }

    /** @return array{range_hours: array{0:int,1:int}, confidence: string, components: array<string, array{0:int,1:int}>|\stdClass, assumptions: list<string>} */
    public function toArray(): array
    {
        return [
            'range_hours' => $this->rangeHours,
            'confidence' => $this->confidence,
            'components' => $this->components === [] ? new \stdClass() : $this->components,
            'assumptions' => $this->assumptions,
        ];
    }

    /**
     * @param array<mixed> $range
     * @return array{0:int,1:int}
     */
    private static function normalizedRange(array $range, string $subject): array
    {
        if (count($range) !== 2 || !array_key_exists(0, $range) || !array_key_exists(1, $range)) {
            throw new \InvalidArgumentException(sprintf('%s must contain exactly a minimum and a maximum.', $subject));
        }

        $minimum = $range[0];
        $maximum = $range[1];

        if (!is_int($minimum) || !is_int($maximum)) {
            throw new \InvalidArgumentException(sprintf('%s bounds must be integer hours.', $subject));
        }
        if ($minimum < 0 || $maximum < 0) {
            throw new \InvalidArgumentException(sprintf('%s bounds cannot be negative.', $subject));
        }
        if ($minimum > $maximum) {
            throw new \InvalidArgumentException(sprintf('%s minimum cannot exceed its maximum.', $subject));
        }

        return [$minimum, $maximum];
    }
}
