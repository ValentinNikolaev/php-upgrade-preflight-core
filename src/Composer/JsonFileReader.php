<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Support\SensitiveOutputRedactor;

final class JsonFileReader
{
    /** @return array<string, mixed> */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            throw new MissingJsonFileException(
                $path,
                sprintf(
                    'Required Composer file "%s" was not found.',
                    SensitiveOutputRedactor::redact(basename($path))
                )
            );
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new UnreadableJsonFileException(
                $path,
                sprintf(
                    'Unable to read Composer file "%s".',
                    SensitiveOutputRedactor::redact(basename($path))
                )
            );
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidJsonException(
                $path,
                sprintf(
                    'Invalid JSON in Composer file "%s": %s.',
                    SensitiveOutputRedactor::redact(basename($path)),
                    json_last_error_msg()
                )
            );
        }

        return $decoded;
    }
}
