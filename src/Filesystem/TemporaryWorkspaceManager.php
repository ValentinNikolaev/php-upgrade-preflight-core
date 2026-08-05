<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Filesystem;

final class TemporaryWorkspaceManager
{
    public function createFromProject(string $projectPath): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-';
        $tempPath = $base . bin2hex(random_bytes(8));

        if (!mkdir($tempPath, 0700, true) && !is_dir($tempPath)) {
            throw new \RuntimeException(sprintf('Unable to create temporary workspace "%s".', $tempPath));
        }

        foreach (['composer.json', 'composer.lock'] as $file) {
            $source = $projectPath . DIRECTORY_SEPARATOR . $file;
            if (!is_file($source)) {
                throw new \RuntimeException(sprintf('Required file "%s" was not found.', $source));
            }

            copy($source, $tempPath . DIRECTORY_SEPARATOR . $file);
        }

        return $tempPath;
    }

    public function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
