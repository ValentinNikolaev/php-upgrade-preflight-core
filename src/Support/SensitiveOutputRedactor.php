<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Support;

final class SensitiveOutputRedactor
{
    public const REDACTED = '[REDACTED]';
    public const REDACTED_TOKEN = '[REDACTED_TOKEN]';
    public const REDACTED_URL = '[REDACTED_URL]';

    public static function redact(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $redacted = self::replace(
            '/\b(COMPOSER_AUTH\s*=)[^\r\n]*/i',
            '$1' . self::REDACTED,
            $value
        );

        $redacted = self::replace(
            '/\b((?:Proxy-)?Authorization\s*:\s*)[^\r\n]*/i',
            '$1' . self::REDACTED,
            $redacted
        );

        $redacted = self::replace(
            '/\b(Basic|Bearer)\s+[A-Za-z0-9._~+\/=:-]+/i',
            '$1 ' . self::REDACTED,
            $redacted
        );

        $redacted = self::replace(
            '~(?<![A-Za-z0-9])(?:https?|ssh|git)://[^\s<>"\']+~i',
            self::REDACTED_URL,
            $redacted
        );

        $redacted = self::replace(
            '~(?<![A-Za-z0-9._-])(?:git|hg|svn)@[A-Za-z0-9.-]+:[^\s<>"\']+~i',
            self::REDACTED_URL,
            $redacted
        );

        $fieldNames = 'username|user|password|passwd|pwd|token|access[_-]?token|auth[_-]?token'
            . '|api[_-]?key|apikey|secret|client[_-]?secret|github-oauth|gitlab-oauth';
        $quotedFieldPattern = '/((?:"|\')?(?:' . $fieldNames . ')(?:"|\')?\s*[:=]\s*)(["\'])(.*?)\2/i';
        $callbackResult = preg_replace_callback(
            $quotedFieldPattern,
            static fn (array $matches): string => $matches[1] . $matches[2] . self::REDACTED . $matches[2],
            $redacted
        );
        $redacted = $callbackResult === null ? self::REDACTED : $callbackResult;

        $unquotedFieldPattern = '/(\b(?:' . $fieldNames . ')\s*[:=]\s*)'
            . '(?!["\']|\[REDACTED(?:_TOKEN|_URL)?\])[^\s,;}\]]+/i';
        $redacted = self::replace(
            $unquotedFieldPattern,
            '$1' . self::REDACTED,
            $redacted
        );

        $redacted = self::replace(
            '/\b(?:github_pat_[A-Za-z0-9_]{10,}|gh[pousr]_[A-Za-z0-9]{10,}'
            . '|glpat-[A-Za-z0-9_-]{10,}|xox[baprs]-[A-Za-z0-9-]{10,}'
            . '|sk_(?:live|test)_[A-Za-z0-9]{10,}|AKIA[0-9A-Z]{16})\b/',
            self::REDACTED_TOKEN,
            $redacted
        );

        return $redacted;
    }

    private static function replace(string $pattern, string $replacement, string $value): string
    {
        $redacted = preg_replace($pattern, $replacement, $value);

        return $redacted === null ? self::REDACTED : $redacted;
    }
}
