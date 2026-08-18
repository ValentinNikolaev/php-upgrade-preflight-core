<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Model\CandidateLockEvidence;
use PhpUpgradePreflight\Core\Model\ComposerLock;

/**
 * Owns the filesystem access behind candidate lockfile evidence so CandidateLockEvidence itself
 * stays a pure value object that can be built and tested without a real filesystem.
 */
final class CandidateLockFileReader
{
    /** Fingerprint LF-normalized lockfile bytes so evidence is stable across operating systems. */
    public function read(string $path, ComposerLock $lock): CandidateLockEvidence
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Unable to fingerprint the candidate Composer lockfile.');
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Unable to fingerprint the candidate Composer lockfile.');
        }

        $normalizedContents = str_replace(["\r\n", "\r"], "\n", $contents);

        return CandidateLockEvidence::fromLock($lock, hash('sha256', $normalizedContents));
    }
}
