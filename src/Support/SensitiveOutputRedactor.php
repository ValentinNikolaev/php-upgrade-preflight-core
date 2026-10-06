<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Support;

final class SensitiveOutputRedactor
{
    public const REDACTED = '[REDACTED]';
    /**
     * Returned when a pattern fails at runtime — a PCRE backtrack or recursion limit — instead of
     * matching nothing. The value is still withheld, and the distinct marker keeps a failed pass
     * distinguishable from a value that was genuinely redacted.
     */
    public const REDACTION_FAILED = '[REDACTION_FAILED]';
    public const REDACTED_TOKEN = '[REDACTED_TOKEN]';
    public const REDACTED_URL = '[REDACTED_URL]';

    public static function redact(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $redacted = self::redactComposerAuthAssignments($value);

        $redacted = self::replace(
            '/(\[(?:github-oauth|gitlab-oauth|gitlab-token|bitbucket-oauth|bearer|http-basic|custom-headers)(?:\.[^\]]+)?\]\s*)[^\r\n]*/i',
            '$1' . self::REDACTED,
            $redacted
        );

        $redacted = self::replace(
            '/(?<![A-Za-z0-9_.-])((?:(?:REDIRECT_)?HTTP_|Proxy-)?Authorization\s*(?:=>|=|:)\s*)[^\r\n]*/i',
            '$1' . self::REDACTED,
            $redacted
        );

        $redacted = self::redactCredentialBearingUrls($redacted);

        $redacted = self::replace(
            '~(?<![A-Za-z0-9._-])[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[^\s<>"\']+/[^\s<>"\']+~i',
            self::REDACTED_URL,
            $redacted
        );

        $redacted = self::redactNamedCredentialValues($redacted);

        $redacted = self::replace(
            '/\b(Basic|Bearer)\s+[A-Za-z0-9._~+\/=:-]+/i',
            '$1 ' . self::REDACTED,
            $redacted
        );

        $redacted = self::replace(
            '~(?<![A-Za-z0-9_-])(?:'
            . 'github_pat_[A-Za-z0-9_]{10,}'
            . '|gh[pousr]_[A-Za-z0-9]{10,}'
            . '|glpat-[A-Za-z0-9_-]{10,}'
            . '|xox[baprs]-[A-Za-z0-9-]{10,}'
            . '|xapp-[A-Za-z0-9-]{10,}'
            . '|(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{10,}'
            . '|whsec_[A-Za-z0-9]{10,}'
            . '|sk-(?:(?:proj|svcacct)-)?[A-Za-z0-9_-]{20,}'
            . '|npm_[A-Za-z0-9]{20,}'
            . '|pypi-[A-Za-z0-9_-]{20,}'
            . '|AIza[A-Za-z0-9_-]{20,}'
            . '|GOCSPX-[A-Za-z0-9_-]{10,}'
            . '|SG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}'
            . '|(?:AKIA|ASIA)[0-9A-Z]{16}'
            . '|eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}'
            . ')(?![A-Za-z0-9_-])~',
            self::REDACTED_TOKEN,
            $redacted
        );

        return $redacted;
    }

    public static function redactStructured(mixed $value): mixed
    {
        return self::redactStructuredValue($value, false, new \SplObjectStorage());
    }

    private static function redactStructuredValue(
        mixed $value,
        bool $sensitive,
        \SplObjectStorage $seen
    ): mixed {
        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $key => $item) {
                $redactedKey = is_string($key) ? self::redact($key) : $key;
                $redacted[$redactedKey] = self::redactStructuredValue(
                    $item,
                    $sensitive || (is_string($key) && self::isSensitiveStructuredKey($key)),
                    $seen
                );
            }

            return $redacted;
        }

        if (is_object($value)) {
            if ($seen->contains($value)) {
                return self::REDACTED;
            }
            $seen->attach($value);

            try {
                if ($value instanceof \JsonSerializable) {
                    return self::redactStructuredValue($value->jsonSerialize(), $sensitive, $seen);
                }

                return self::redactObjectProperties($value, $sensitive, $seen);
            } catch (\Throwable) {
                return self::REDACTED;
            } finally {
                $seen->detach($value);
            }
        }

        if ($sensitive && $value !== null) {
            return self::REDACTED;
        }

        return is_string($value) ? self::redact($value) : $value;
    }

    private static function redactObjectProperties(
        object $value,
        bool $sensitive,
        \SplObjectStorage $seen
    ): \stdClass {
        $redacted = new \stdClass();

        foreach (get_object_vars($value) as $key => $item) {
            $redactedKey = self::redact($key);
            $redacted->{$redactedKey} = self::redactStructuredValue(
                $item,
                $sensitive || self::isSensitiveStructuredKey($key),
                $seen
            );
        }

        return $redacted;
    }

    private static function redactComposerAuthAssignments(string $value): string
    {
        $offset = 0;
        $pattern = '/(?<![A-Za-z0-9_.-])(?:(?:(?:\\\\)*["\'])?COMPOSER_AUTH(?:(?:\\\\)*["\'])?|\[COMPOSER_AUTH\])\s*(?:=>|=|:)\s*/i';

        while (true) {
            $matched = preg_match($pattern, $value, $matches, PREG_OFFSET_CAPTURE, $offset);
            if ($matched === false) {
                return self::REDACTION_FAILED;
            }

            if ($matched === 0) {
                return $value;
            }

            $prefix = $matches[0][0];
            $start = $matches[0][1];
            $valueStart = $start + strlen($prefix);
            $end = self::credentialValueEnd($value, $valueStart);
            $replacement = $prefix . self::REDACTED;
            $value = substr($value, 0, $start) . $replacement . substr($value, $end);
            $offset = $start + strlen($replacement);
        }
    }

    private static function redactNamedCredentialValues(string $value): string
    {
        $offset = 0;
        $fields = self::credentialFieldNames();
        $pattern = '/(?<![A-Za-z0-9_.-])(?:(?:(?:\\\\)*["\'])?(?:' . $fields
            . ')(?:(?:\\\\)*["\'])?|\[(?:' . $fields . ')\])\s*(?:=>|=|:)\s*/i';

        while (true) {
            $matched = preg_match($pattern, $value, $matches, PREG_OFFSET_CAPTURE, $offset);
            if ($matched === false) {
                return self::REDACTION_FAILED;
            }

            if ($matched === 0) {
                return $value;
            }

            $prefix = $matches[0][0];
            $start = $matches[0][1];
            $valueStart = $start + strlen($prefix);
            if ($valueStart >= strlen($value)) {
                $offset = $valueStart;
                continue;
            }

            $first = $value[$valueStart];
            if ($first === '"' || $first === "'") {
                [$end, $closed] = self::quotedValueEnd($value, $valueStart);
                $replacement = $prefix . $first . self::REDACTED . ($closed ? $first : '');
            } else {
                $end = self::credentialValueEnd($value, $valueStart);
                $replacement = $prefix . self::REDACTED;
            }

            $value = substr($value, 0, $start) . $replacement . substr($value, $end);
            $offset = $start + strlen($replacement);
        }
    }

    private static function credentialValueEnd(string $value, int $start): int
    {
        if ($start >= strlen($value)) {
            return $start;
        }

        $first = $value[$start];
        if ($first === '{' || $first === '[') {
            return self::structuredValueEnd($value, $start);
        }

        $arrayPrefix = substr($value, $start);
        if (preg_match('/\AArray\s*\(/i', $arrayPrefix, $matches) === 1) {
            $openingParenthesis = $start + strlen($matches[0]) - 1;

            return self::parenthesizedValueEnd($value, $openingParenthesis);
        }

        if ($first === '"' || $first === "'") {
            return self::quotedValueEnd($value, $start)[0];
        }

        $length = strcspn($value, " \t\r\n", $start);

        return $start + $length;
    }

    private static function parenthesizedValueEnd(string $value, int $start): int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($value);

        for ($index = $start; $index < $length; ++$index) {
            $character = $value[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($character === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if ($character === '(') {
                ++$depth;
                continue;
            }
            if ($character !== ')') {
                continue;
            }

            --$depth;
            if ($depth === 0) {
                return $index + 1;
            }
        }

        return $length;
    }

    private static function structuredValueEnd(string $value, int $start): int
    {
        $expectedClosers = [];
        $quote = null;
        $escaped = false;
        $length = strlen($value);

        for ($index = $start; $index < $length; ++$index) {
            $character = $value[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($character === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }

            if ($character === '{') {
                $expectedClosers[] = '}';
                continue;
            }

            if ($character === '[') {
                $expectedClosers[] = ']';
                continue;
            }

            if ($character !== '}' && $character !== ']') {
                continue;
            }

            $expected = array_pop($expectedClosers);
            if ($expected !== $character) {
                return $length;
            }

            if ($expectedClosers === []) {
                return $index + 1;
            }
        }

        return $length;
    }

    /** @return array{int, bool} */
    private static function quotedValueEnd(string $value, int $start): array
    {
        $quote = $value[$start];
        $escaped = false;
        $length = strlen($value);

        for ($index = $start + 1; $index < $length; ++$index) {
            $character = $value[$index];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($character === '\\') {
                $escaped = true;
                continue;
            }

            if ($character === $quote) {
                return [$index + 1, true];
            }
        }

        return [$length, false];
    }

    private static function redactCredentialBearingUrls(string $value): string
    {
        return self::replaceCallback(
            '~(?<![A-Za-z0-9])(?:[A-Za-z][A-Za-z0-9+.-]*:)?(?://|(?:\\\\+/){2})[^\s<>"\']+~i',
            static function (array $matches): string {
                $url = $matches[0];

                return self::urlContainsCredentials($url) ? self::REDACTED_URL : $url;
            },
            $value
        );
    }

    private static function urlContainsCredentials(string $url): bool
    {
        $normalized = preg_replace('~\\\\+/~', '/', $url);
        if ($normalized === null) {
            return true;
        }
        $schemeEnd = strpos($normalized, '://');
        $authorityStart = $schemeEnd === false && str_starts_with($normalized, '//')
            ? 2
            : ($schemeEnd === false ? null : $schemeEnd + 3);
        if ($authorityStart === null) {
            return true;
        }
        $authorityLength = strcspn($normalized, '/?#', $authorityStart);
        $authority = substr($normalized, $authorityStart, $authorityLength);
        if (str_contains($authority, '@')) {
            return true;
        }

        $matched = preg_match(
            '/(?:[?&#;]|^)(?:access[_-]?token|auth[_-]?token|oauth[_-]?token|token|api[_-]?key|apikey|key|secret|signature|sig|password|passwd|pwd|x-amz-(?:credential|signature))\s*=/i',
            $normalized
        );

        return $matched !== 0;
    }

    private static function isSensitiveStructuredKey(string $key): bool
    {
        $normalized = trim($key, " \t\n\r\0\x0B\"'");
        $normalized = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $normalized) ?? $normalized;
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $normalized) ?? $normalized;
        $normalized = strtolower(str_replace('_', '-', $normalized));
        $sensitive = [
            'auth',
            'authorization',
            'proxy-authorization',
            'http-authorization',
            'redirect-http-authorization',
            'composer-auth',
            'username',
            'user',
            'password',
            'passwd',
            'pwd',
            'token',
            'access-token',
            'auth-token',
            'refresh-token',
            'id-token',
            'session-token',
            'api-key',
            'apikey',
            'secret',
            'client-secret',
            'consumer-secret',
            'github-oauth',
            'gitlab-oauth',
            'gitlab-token',
            'bitbucket-oauth',
            'bearer',
            'http-basic',
            'custom-headers',
            'aws-access-key-id',
            'aws-secret-access-key',
            'aws-session-token',
            'private-key',
            'credential',
            'credentials',
        ];

        foreach ($sensitive as $name) {
            if ($normalized === $name || str_starts_with($normalized, $name . '.')) {
                return true;
            }
        }

        foreach (['-authorization', '-username', '-password', '-passwd', '-token', '-api-key', '-secret', '-credential', '-credentials'] as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function credentialFieldNames(): string
    {
        return '(?:redirect[_-]?http[_-]?|http[_-]?|proxy[_-]?)?authorization'
            . '|username|user|password|passwd|pwd|token|access[_-]?token|auth[_-]?token'
            . '|refresh[_-]?token|id[_-]?token|session[_-]?token|api[_-]?key|apikey|secret'
            . '|client[_-]?secret|consumer[_-]?secret|private[_-]?key|credential|credentials'
            . '|github-oauth|gitlab-oauth|gitlab-token|bitbucket-oauth|bearer|http-basic|custom-headers'
            . '|aws[_-]?access[_-]?key[_-]?id|aws[_-]?secret[_-]?access[_-]?key|aws[_-]?session[_-]?token';
    }

    /** @param callable(array<int, string>): string $callback */
    private static function replaceCallback(string $pattern, callable $callback, string $value): string
    {
        $redacted = preg_replace_callback($pattern, $callback, $value);

        return $redacted === null ? self::REDACTION_FAILED : $redacted;
    }

    private static function replace(string $pattern, string $replacement, string $value): string
    {
        $redacted = preg_replace($pattern, $replacement, $value);

        return $redacted === null ? self::REDACTION_FAILED : $redacted;
    }
}
