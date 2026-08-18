<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Model\TargetPlatformProfile;

/**
 * Owns the filesystem access behind target platform profiles so TargetPlatformProfile itself stays
 * a pure value object that can be built and tested without a real filesystem.
 *
 * Unreadable profiles stay \InvalidArgumentException rather than JsonFileException because the CLI
 * and Artisan entry points classify them as invalid invocation, not as an analysis failure.
 */
final class TargetPlatformProfileFileReader
{
    public function read(string $path): TargetPlatformProfile
    {
        $json = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
        if (!is_string($json)) {
            throw new \InvalidArgumentException('Target platform profile file could not be read.');
        }

        return TargetPlatformProfile::fromJson($json, TargetPlatformProfile::PROVENANCE_FILE);
    }
}
