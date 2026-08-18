<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Reporting;

use Symfony\Component\Filesystem\Filesystem;

final class SymfonyReportDestinationFilesystem implements ReportDestinationFilesystem
{
    private Filesystem $filesystem;

    public function __construct(?Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem();
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function resolve(string $path): string|false
    {
        return realpath($path);
    }

    public function dumpFile(string $path, string $contents): void
    {
        $this->filesystem->dumpFile($path, $contents);
    }
}
