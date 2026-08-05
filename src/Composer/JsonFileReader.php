<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

final class JsonFileReader
{
    /** @return array<string, mixed> */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Required file "%s" was not found.', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read "%s".', $path));
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf('Invalid JSON in "%s": %s.', $path, json_last_error_msg()));
        }

        return $decoded;
    }
}
