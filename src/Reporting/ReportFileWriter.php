<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Reporting;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ReportFileWriter
{
    private Filesystem $filesystem;

    public function __construct(?Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem();
    }

    public function write(string $projectPath, string $outputPath, string $contents): string
    {
        if (trim($outputPath) === '') {
            throw new \InvalidArgumentException('Report output path cannot be empty.');
        }

        $workingDirectory = getcwd();
        if ($workingDirectory === false) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }

        $absolutePath = Path::makeAbsolute($outputPath, $workingDirectory);
        $resolvedPath = $this->resolvedDestination($absolutePath);
        $resolvedProject = realpath($projectPath);

        if ($resolvedProject === false) {
            throw new \InvalidArgumentException(sprintf('Project path "%s" does not exist.', $projectPath));
        }

        if ($this->isWithin($resolvedProject, $resolvedPath)) {
            throw new \InvalidArgumentException('Report output must be outside the analyzed project to preserve its read-only input contract.');
        }

        if (is_dir($absolutePath)) {
            throw new \InvalidArgumentException(sprintf('Report output path "%s" is a directory.', $outputPath));
        }

        $this->filesystem->dumpFile($absolutePath, $contents);

        return Path::canonicalize($absolutePath);
    }

    private function resolvedDestination(string $absolutePath): string
    {
        $resolved = realpath($absolutePath);
        if ($resolved !== false) {
            return Path::canonicalize($resolved);
        }

        $parent = realpath(dirname($absolutePath));
        if ($parent === false || !is_dir($parent)) {
            throw new \InvalidArgumentException(sprintf('Report output directory "%s" does not exist.', dirname($absolutePath)));
        }

        return Path::canonicalize($parent . DIRECTORY_SEPARATOR . basename($absolutePath));
    }

    private function isWithin(string $directory, string $path): bool
    {
        $directory = Path::canonicalize($directory);
        $path = Path::canonicalize($path);

        if (DIRECTORY_SEPARATOR === '\\') {
            $directory = strtolower($directory);
            $path = strtolower($path);
        }

        return $path === $directory || str_starts_with($path, rtrim($directory, '/') . '/');
    }
}
