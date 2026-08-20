<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

final class PackageMetadataLookupMode
{
    /** Use only metadata already available to Composer locally. */
    public const LOCAL_CACHE_ONLY = 'local_cache_only';

    /** Resolve metadata through the repositories configured for the project. */
    public const PROJECT_REPOSITORIES = 'project_repositories';

    public static function assertSupported(string $mode): void
    {
        if (!in_array($mode, [self::LOCAL_CACHE_ONLY, self::PROJECT_REPOSITORIES], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported package metadata lookup mode "%s".', $mode));
        }
    }

    public static function allowsNetwork(string $mode): bool
    {
        self::assertSupported($mode);

        return $mode === self::PROJECT_REPOSITORIES;
    }
}
