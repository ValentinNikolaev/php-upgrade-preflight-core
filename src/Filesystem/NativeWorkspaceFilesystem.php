<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Filesystem;

final class NativeWorkspaceFilesystem implements WorkspaceFilesystem
{
    public function createDirectory(string $path, int $mode, bool $recursive): bool
    {
        return @mkdir($path, $mode, $recursive);
    }

    public function copy(string $source, string $destination): bool
    {
        return @copy($source, $destination);
    }

    public function unlink(string $path): bool
    {
        return @unlink($path);
    }

    public function removeDirectory(string $path): bool
    {
        return @rmdir($path);
    }
}
