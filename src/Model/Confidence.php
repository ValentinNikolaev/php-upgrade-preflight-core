<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

/**
 * Canonical confidence scale for report fields that express how strongly a
 * statement is supported by evidence.
 *
 * The scale is shared by evidence records and effort estimates so the same
 * three values are validated once instead of being re-declared per model.
 * Values remain plain strings so report serialization is unchanged.
 */
final class Confidence
{
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::LOW, self::MEDIUM, self::HIGH];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /** @param string $subject Field name used in the failure message, for example "evidence confidence". */
    public static function assert(string $value, string $subject): void
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException(sprintf('Unsupported %s "%s".', $subject, $value));
        }
    }
}
