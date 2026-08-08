<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Filesystem;

final class TemporaryWorkspaceManager implements WorkspaceManager
{
    private WorkspaceFilesystem $filesystem;

    public function __construct(?WorkspaceFilesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? new NativeWorkspaceFilesystem();
    }

    public function createFromProject(string $projectPath): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-';
        $tempPath = $base . bin2hex(random_bytes(8));

        if (!$this->filesystem->createDirectory($tempPath, 0700, true) && !is_dir($tempPath)) {
            throw new \RuntimeException(sprintf('Unable to create temporary workspace "%s".', $tempPath));
        }

        try {
            foreach (['composer.json', 'composer.lock'] as $file) {
                $source = $projectPath . DIRECTORY_SEPARATOR . $file;
                if (!is_file($source)) {
                    throw new \RuntimeException(sprintf('Required file "%s" was not found.', $source));
                }

                $destination = $tempPath . DIRECTORY_SEPARATOR . $file;
                if (!$this->filesystem->copy($source, $destination)) {
                    throw new \RuntimeException(sprintf('Unable to copy "%s" to temporary workspace "%s".', $source, $destination));
                }
            }
        } catch (\Throwable $exception) {
            try {
                $this->remove($tempPath);
            } catch (\Throwable $cleanupException) {
                throw new WorkspaceCleanupException($tempPath, sprintf(
                    '%s Cleanup of partial workspace "%s" also failed: %s',
                    $exception->getMessage(),
                    $tempPath,
                    $cleanupException->getMessage()
                ), $cleanupException);
            }

            throw $exception;
        }

        return $tempPath;
    }

    public function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    $this->unlink($item->getPathname());
                } elseif ($item->isDir()) {
                    $this->removeDirectory($item->getPathname());
                } else {
                    $this->unlink($item->getPathname());
                }
            }

            $this->removeDirectory($path);
        } catch (WorkspaceCleanupException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new WorkspaceCleanupException($path, $exception->getMessage(), $exception);
        }
    }

    private function unlink(string $path): void
    {
        if ($this->filesystem->unlink($path)) {
            return;
        }

        // PHP removes directory links with rmdir() on Windows, while Unix
        // removes them with unlink(). The fallback removes the link itself;
        // RecursiveDirectoryIterator does not follow directory links here.
        if (is_dir($path) && $this->filesystem->removeDirectory($path)) {
            return;
        }

        if (is_file($path) || is_link($path) || is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to remove temporary workspace file "%s".', $path));
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!$this->filesystem->removeDirectory($path) && is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to remove temporary workspace directory "%s".', $path));
        }
    }
}
