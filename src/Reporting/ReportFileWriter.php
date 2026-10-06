<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Reporting;

use Symfony\Component\Filesystem\Path;

final class ReportFileWriter
{
    private ReportDestinationFilesystem $filesystem;

    public function __construct(?ReportDestinationFilesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? new SymfonyReportDestinationFilesystem();
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
        $resolvedProject = $this->filesystem->resolve($projectPath);

        if ($resolvedProject === false) {
            throw new \InvalidArgumentException('The analyzed project path does not exist.');
        }

        if ($this->isWithin($resolvedProject, $resolvedPath)) {
            throw new \InvalidArgumentException('Report output must be outside the analyzed project to preserve its read-only input contract.');
        }

        if ($this->filesystem->isDirectory($absolutePath)) {
            throw new \InvalidArgumentException('The report output path is a directory.');
        }

        if ($this->filesystem->isFile($absolutePath) && !$this->filesystem->isWritable($absolutePath)) {
            throw new \InvalidArgumentException('The report output path is not writable.');
        }

        if (!$this->filesystem->exists($absolutePath) && !$this->filesystem->isWritable(dirname($absolutePath))) {
            throw new \InvalidArgumentException('The report output directory is not writable.');
        }

        return Path::canonicalize($absolutePath);
    }

    private function resolvedDestination(string $absolutePath): string
    {
        $resolved = $this->filesystem->resolve($absolutePath);
        if ($resolved !== false) {
            return Path::canonicalize($resolved);
        }

        $parent = $this->filesystem->resolve(dirname($absolutePath));
        if ($parent === false || !$this->filesystem->isDirectory($parent)) {
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
