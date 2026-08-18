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

        $structured = json_decode($contents);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidJsonException(
                $path,
                sprintf(
                    'Invalid JSON in Composer file "%s": %s.',
                    SensitiveOutputRedactor::redact(basename($path)),
                    json_last_error_msg()
                )
            );
        }

        if (!$structured instanceof \stdClass) {
            throw new InvalidJsonException(
                $path,
                sprintf('Composer file "%s" must contain a JSON object.', SensitiveOutputRedactor::redact(basename($path)))
            );
        }

        $this->assertManifestObjectSections($structured, $path);

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new \LogicException('A JSON object must decode to an associative array.');
        }

        return $decoded;
    }

    private function assertManifestObjectSections(\stdClass $document, string $path): void
    {
        if (strtolower(basename($path)) !== 'composer.json') {
            return;
        }

        $manifest = get_object_vars($document);
        if (!array_key_exists('config', $manifest)) {
            return;
        }

        if (!$manifest['config'] instanceof \stdClass) {
            throw $this->invalidManifestSection($path, 'its "config" section is not an object');
        }

        $config = get_object_vars($manifest['config']);
        if (array_key_exists('platform', $config) && !$config['platform'] instanceof \stdClass) {
            throw $this->invalidManifestSection($path, 'its "config.platform" section is not an object');
        }
    }

    private function invalidManifestSection(string $path, string $reason): InvalidJsonException
    {
        return new InvalidJsonException(
            $path,
            sprintf('Composer file "composer.json" cannot be analyzed because %s.', $reason)
        );
    }
}
