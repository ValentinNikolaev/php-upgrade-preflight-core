<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Support;

final class OutputExcerpt
{
    /**
     * Bytes kept past the excerpt budget while redacting. A credential that straddles the
     * excerpt boundary must still be matched as a whole value, so redaction sees the excerpt
     * plus this margin. Bounding the redactor's input also keeps pattern matching away from
     * multi-megabyte solver output, where a PCRE backtrack limit would discard everything.
     */
    private const REDACTION_MARGIN_BYTES = 4096;

    public static function bounded(string $value, int $maxBytes = 4000): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Output excerpt length cannot be negative.');
        }

        $originalBytes = strlen($value);
        $value = SensitiveOutputRedactor::redact(
            self::cutToValidUtf8($value, $maxBytes + self::REDACTION_MARGIN_BYTES)
        );

        if (strlen($value) <= $maxBytes && $originalBytes <= $maxBytes + self::REDACTION_MARGIN_BYTES) {
            return $value;
        }

        $marker = sprintf('%s[TRUNCATED: %d bytes of output omitted]', PHP_EOL, $originalBytes);
        if ($maxBytes <= strlen($marker)) {
            return self::cutToValidUtf8($value, $maxBytes);
        }

        return self::cutToValidUtf8($value, $maxBytes - strlen($marker)) . $marker;
    }

    private static function cutToValidUtf8(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $excerpt = substr($value, 0, $maxBytes);
        while ($excerpt !== '' && preg_match('//u', $excerpt) !== 1) {
            $excerpt = substr($excerpt, 0, -1);
        }

        return $excerpt;
    }
}
