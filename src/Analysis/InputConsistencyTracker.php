<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Source\SourceScanLimits;
use PhpUpgradePreflight\Core\Source\SourceUsageScanner;
use Symfony\Component\Filesystem\Path;

/** Detects changes to inputs used at different times during one analysis. */
final class InputConsistencyTracker
{
    private string $projectPath;
    /** @var array<string, string|null> */
    private array $composerDigests;
    /** @var array<string, string|null> */
    private array $sourceDigests = [];
    /** @var array<string, true> */
    private array $changed = [];

    public function __construct(string $projectPath)
    {
        $this->projectPath = $projectPath;
        $this->composerDigests = $this->digests([
            $projectPath . DIRECTORY_SEPARATOR . 'composer.json',
            $projectPath . DIRECTORY_SEPARATOR . 'composer.lock',
        ]);
    }

    public function checkComposer(): void
    {
        $this->recordChanges($this->composerDigests, $this->digests(array_keys($this->composerDigests)));
    }

    /** @param list<string> $paths */
    public function captureSource(SourceUsageScanner $scanner, ProjectState $project, array $paths): void
    {
        $selectionUncertainties = [];
        $this->sourceDigests = $this->sourceDigests(
            $scanner->selectedPhpFiles($project, $paths, $selectionUncertainties, false),
            $scanner->limits()
        );
    }

    /**
     * @param list<string> $paths
     * @param array<string, string> $readDigests
     */
    public function checkSource(SourceUsageScanner $scanner, ProjectState $project, array $paths, array $readDigests): void
    {
        foreach ($readDigests as $path => $digest) {
            if (!array_key_exists($path, $this->sourceDigests) || $this->sourceDigests[$path] !== $digest) {
                $this->changed[$path] = true;
            }
        }
        $selectionUncertainties = [];
        $current = $this->sourceDigests(
            $scanner->selectedPhpFiles($project, $paths, $selectionUncertainties, false),
            $scanner->limits()
        );
        $this->recordChanges($this->sourceDigests, $current);
    }

    /** @return list<string> */
    public function uncertainties(): array
    {
        if ($this->changed === []) {
            return [];
        }

        $paths = array_keys($this->changed);
        sort($paths, SORT_STRING);
        $names = array_map(function (string $path): string {
            $root = rtrim(Path::canonicalize((string) (realpath($this->projectPath) ?: $this->projectPath)), '/') . '/';
            $path = Path::canonicalize($path);

            return str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);
        }, array_slice($paths, 0, 10));
        $suffix = count($paths) > 10 ? sprintf(' and %d more', count($paths) - 10) : '';

        return [sprintf(
            'Project input changed during analysis (%s%s); dependency and source findings may describe different project states.',
            implode(', ', $names),
            $suffix
        )];
    }

    /**
     * @param list<string> $files
     * @return array<string, string|null>
     */
    private function digests(array $files): array
    {
        $digests = [];
        foreach ($files as $file) {
            $digest = @hash_file('sha256', $file);
            $digests[$file] = $digest === false ? null : $digest;
        }

        return $digests;
    }

    /**
     * Fingerprints only source bytes the configured scanner could inspect. Metadata is
     * sufficient for files that the scanner will omit because of its byte budgets.
     *
     * @param list<string> $files
     * @return array<string, string|null>
     */
    private function sourceDigests(array $files, SourceScanLimits $limits): array
    {
        $digests = [];
        $totalBytes = 0;

        foreach ($files as $file) {
            $remaining = $limits->maxTotalBytes() - $totalBytes;
            if ($remaining < 1) {
                $digests[$file] = $this->metadataDigest($file);
                continue;
            }

            $readLimit = min($limits->maxFileBytes(), $remaining);
            $digests[$file] = null;
            $handle = @fopen($file, 'rb');
            $contents = $handle === false ? false : stream_get_contents($handle, $readLimit + 1);
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($contents !== false) {
                $totalBytes += strlen($contents);
                $digests[$file] = strlen($contents) > $readLimit
                    ? $this->metadataDigest($file)
                    : hash('sha256', $contents);
            }
        }

        return $digests;
    }

    private function metadataDigest(string $file): string
    {
        $stat = @stat($file);
        $metadata = $stat === false ? [] : $stat;

        return 'metadata:' . hash('sha256', implode(':', [
            (string) ($metadata['size'] ?? ''),
            (string) ($metadata['mtime'] ?? ''),
            (string) ($metadata['ctime'] ?? ''),
        ]));
    }

    /**
     * @param array<string, string|null> $before
     * @param array<string, string|null> $after
     */
    private function recordChanges(array $before, array $after): void
    {
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $path) {
            if (!array_key_exists($path, $before) || !array_key_exists($path, $after) || $before[$path] !== $after[$path]) {
                $this->changed[$path] = true;
            }
        }
    }
}
