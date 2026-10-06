<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

/**
 * Canonical severity scale for report fields that grade impact.
 *
 * The scale is shared by framework compatibility findings, source-impact
 * findings, and risk levels, so the same three values are validated once
 * instead of being re-declared per model. Values remain plain strings so
 * report serialization is unchanged.
 */
final class Severity
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

    /** @param string $subject Field name used in the failure message, for example "risk level". */
    public static function assert(string $value, string $subject): void
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException(sprintf('Unsupported %s "%s".', $subject, $value));
        }
    }
}
