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
        $absolutePath = $this->validateDestination($projectPath, $outputPath);
        $this->filesystem->dumpFile($absolutePath, $contents);

        return Path::canonicalize($absolutePath);
    }

    public function validateDestination(string $projectPath, string $outputPath): string
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
            throw new \InvalidArgumentException('The analyzed project path does not exist.');
        }

        if ($this->isWithin($resolvedProject, $resolvedPath)) {
            throw new \InvalidArgumentException('Report output must be outside the analyzed project to preserve its read-only input contract.');
        }

        if (is_dir($absolutePath)) {
            throw new \InvalidArgumentException('The report output path is a directory.');
        }

        if (is_file($absolutePath) && !is_writable($absolutePath)) {
            throw new \InvalidArgumentException('The report output path is not writable.');
        }

        if (!file_exists($absolutePath) && !is_writable(dirname($absolutePath))) {
            throw new \InvalidArgumentException('The report output directory is not writable.');
        }

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
            throw new \InvalidArgumentException('The report output directory does not exist.');
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
