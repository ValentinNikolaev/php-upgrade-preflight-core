<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\SourceUsage;

final class SourceUsageScanner
{
    /**
     * @param list<string> $paths
     * @param list<Evidence> $evidence
     * @return list<SourceUsage>
     */
    public function scan(ProjectState $project, array $paths, array &$evidence): array
    {
        $usages = [];

        foreach ($this->phpFiles($project->path, $paths) as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            foreach ($this->extractSymbols($contents) as $usageType => $symbols) {
                foreach ($symbols as $symbol) {
                    $id = 'source-' . (count($evidence) + 1);
                    $relative = $this->relativePath($project->path, $file);
                    $evidence[] = new Evidence($id, Evidence::E3_PROJECT_SOURCE, sprintf('Detected %s in %s.', $symbol, $relative), 'high', [
                        'file' => $relative,
                        'usage_type' => $usageType,
                    ]);
                    $usages[] = new SourceUsage($relative, $symbol, $usageType, [$id]);
                }
            }
        }

        return $usages;
    }

    /** @param list<string> $paths @return iterable<string> */
    private function phpFiles(string $projectPath, array $paths): iterable
    {
        foreach ($paths as $path) {
            $fullPath = $projectPath . DIRECTORY_SEPARATOR . trim($path, '/\\');
            if (is_file($fullPath) && substr($fullPath, -4) === '.php') {
                yield $fullPath;
                continue;
            }

            if (!is_dir($fullPath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fullPath, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                    yield $file->getPathname();
                }
            }
        }
    }

    /** @return array<string, list<string>> */
    private function extractSymbols(string $contents): array
    {
        $symbols = [
            'namespace_import' => [],
            'static_call' => [],
            'class_reference' => [],
        ];

        if (preg_match_all('/^\\s*use\\s+([^;]+);/mi', $contents, $matches) > 0) {
            foreach ($matches[1] as $match) {
                $symbols['namespace_import'][] = trim($match);
            }
        }

        if (preg_match_all('/\\\\?([A-Z][A-Za-z0-9_\\\\]+)::/', $contents, $matches) > 0) {
            foreach ($matches[1] as $match) {
                $symbols['static_call'][] = trim($match, '\\');
            }
        }

        if (preg_match_all('/\\b(?:extends|implements|new)\\s+\\\\?([A-Z][A-Za-z0-9_\\\\]+)/', $contents, $matches) > 0) {
            foreach ($matches[1] as $match) {
                $symbols['class_reference'][] = trim($match, '\\');
            }
        }

        return array_map(static fn (array $values): array => array_values(array_unique($values)), $symbols);
    }

    private function relativePath(string $projectPath, string $file): string
    {
        $prefix = rtrim($projectPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strpos($file, $prefix) === 0 ? substr($file, strlen($prefix)) : $file;
    }
}
