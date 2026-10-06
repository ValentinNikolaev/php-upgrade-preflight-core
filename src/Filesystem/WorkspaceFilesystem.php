<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Filesystem;

interface WorkspaceFilesystem
{
    public function createDirectory(string $path, int $mode, bool $recursive): bool;

    public function copy(string $source, string $destination): bool;

    public function unlink(string $path): bool;

    public function removeDirectory(string $path): bool;
}
