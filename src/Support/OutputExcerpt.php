<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Support;

final class OutputExcerpt
{
    public static function bounded(string $value, int $maxBytes = 4000): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Output excerpt length cannot be negative.');
        }

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
