<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ReportFormat
{
    public const JSON = 'json';
    public const MARKDOWN = 'markdown';

    public static function normalize(string $format): string
    {
        $format = strtolower(trim($format));

        if (!in_array($format, [self::JSON, self::MARKDOWN], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported report format "%s".', $format));
        }

        return $format;
    }
}
