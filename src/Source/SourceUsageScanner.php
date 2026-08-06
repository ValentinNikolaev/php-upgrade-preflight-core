<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use Symfony\Component\Filesystem\Path;

final class SourceUsageScanner
{
    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? $this->createParser();
    }

    /**
     * @param list<string> $paths
     * @param list<Evidence> $evidence
     * @param list<string> $uncertainties
     * @return list<SourceUsage>
     */
    public function scan(
        ProjectState $project,
        array $paths,
        array &$evidence,
        array &$uncertainties = [],
        bool $reportMissingPaths = true
    ): array {
        $usages = [];
        $files = $this->phpFiles($project->path, $paths, $uncertainties, $reportMissingPaths);

        if ($files === []) {
            $uncertainties[] = 'No PHP source files were scanned.';
        }

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                $uncertainties[] = sprintf('Source file "%s" could not be read and was not scanned.', $this->relativePath($project->path, $file));
                continue;
            }

            $relative = $this->relativePath($project->path, $file);

            try {
                $detectedUsages = $this->extractSymbols($contents);
            } catch (Error $exception) {
                $id = $this->nextEvidenceId($evidence);
                $evidence[] = new Evidence($id, Evidence::E3_PROJECT_SOURCE, sprintf('Unable to parse %s.', $relative), 'high', [
                    'file' => $relative,
                    'line' => $exception->getStartLine(),
                    'error' => $exception->getMessage(),
                ]);
                $uncertainties[] = sprintf('Source file "%s" could not be parsed and was not scanned (%s).', $relative, $id);
                continue;
            }

            foreach ($detectedUsages as $detectedUsage) {
                $id = $this->nextEvidenceId($evidence);
                $evidence[] = new Evidence($id, Evidence::E3_PROJECT_SOURCE, sprintf('Detected %s in %s.', $detectedUsage['symbol'], $relative), 'high', [
                    'file' => $relative,
                    'line' => $detectedUsage['line'],
                    'usage_type' => $detectedUsage['usage_type'],
                ]);
                $usages[] = new SourceUsage(
                    $relative,
                    $detectedUsage['symbol'],
                    $detectedUsage['usage_type'],
                    [$id],
                    $detectedUsage['line']
                );
            }
        }

        $uncertainties = array_values(array_unique($uncertainties));

        return $usages;
    }

    /**
     * @param list<string> $paths
     * @param list<string> $uncertainties
     * @return list<string>
     */
    private function phpFiles(string $projectPath, array $paths, array &$uncertainties, bool $reportMissingPaths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (trim($path) === '') {
                $uncertainties[] = 'An empty source path was not scanned.';
                continue;
            }

            $fullPath = $projectPath . DIRECTORY_SEPARATOR . trim($path, '/\\');
            $resolved = realpath($fullPath);

            if ($resolved === false) {
                if ($reportMissingPaths) {
                    $uncertainties[] = sprintf('Source path "%s" does not exist and was not scanned.', $path);
                }

                continue;
            }

            if (!$this->isWithinProject($projectPath, $resolved)) {
                $uncertainties[] = sprintf('Source path "%s" resolves outside the analyzed project and was not scanned.', $path);
                continue;
            }

            if (is_file($resolved)) {
                if (strtolower(substr($resolved, -4)) === '.php') {
                    $files[$resolved] = $resolved;
                }

                continue;
            }

            if (!is_dir($resolved)) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isLink()) {
                        $resolvedLink = realpath($file->getPathname());
                        if ($resolvedLink === false) {
                            $uncertainties[] = sprintf('Source link "%s" could not be resolved and was not scanned.', $this->relativePath($projectPath, $file->getPathname()));
                            continue;
                        }

                        if (!$this->isWithinProject($projectPath, $resolvedLink)) {
                            $uncertainties[] = sprintf('Source link "%s" resolves outside the analyzed project and was not scanned.', $this->relativePath($projectPath, $file->getPathname()));
                            continue;
                        }
                    }

                    if ($file->isFile() && strtolower(substr($file->getFilename(), -4)) === '.php') {
                        $pathname = $file->getPathname();
                        $resolvedFile = realpath($pathname);

                        if ($resolvedFile === false) {
                            $uncertainties[] = sprintf('Source file "%s" could not be resolved and was not scanned.', $this->relativePath($projectPath, $pathname));
                            continue;
                        }

                        if (!$this->isWithinProject($projectPath, $resolvedFile)) {
                            $uncertainties[] = sprintf('Source file "%s" resolves outside the analyzed project and was not scanned.', $this->relativePath($projectPath, $pathname));
                            continue;
                        }

                        $files[$resolvedFile] = $resolvedFile;
                    }
                }
            } catch (\UnexpectedValueException $exception) {
                $uncertainties[] = sprintf('Source path "%s" could not be traversed: %s', $path, $exception->getMessage());
            }
        }

        $files = array_values($files);
        usort($files, static fn (string $left, string $right): int => strcmp(Path::canonicalize($left), Path::canonicalize($right)));

        return $files;
    }

    /** @return list<array{symbol: string, usage_type: string, line: int}> */
    private function extractSymbols(string $contents): array
    {
        $nodes = $this->parser->parse($contents) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $nodes = $traverser->traverse($nodes);
        $usages = [];

        foreach ((new NodeFinder())->find($nodes, static fn (Node $node): bool => true) as $node) {
            if ($node instanceof Stmt\Use_) {
                foreach ($node->uses as $use) {
                    $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $node->type : $use->type;
                    $this->addUsage($usages, (string) $use->name, $this->importUsageType($type), $use->getStartLine());
                }
            } elseif ($node instanceof Stmt\GroupUse) {
                foreach ($node->uses as $use) {
                    $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $node->type : $use->type;
                    $this->addUsage(
                        $usages,
                        (string) $node->prefix . '\\' . (string) $use->name,
                        $this->importUsageType($type),
                        $use->getStartLine()
                    );
                }
            } elseif ($node instanceof Expr\StaticCall || $node instanceof Expr\StaticPropertyFetch || $node instanceof Expr\ClassConstFetch) {
                if ($node->class instanceof Name) {
                    $this->addNameUsage($usages, $node->class, 'static_call', $node->getStartLine());
                }
            } elseif ($node instanceof Expr\New_) {
                if ($node->class instanceof Name) {
                    $this->addNameUsage($usages, $node->class, 'class_reference', $node->getStartLine());
                }
            } elseif ($node instanceof Stmt\Class_) {
                if ($node->extends !== null) {
                    $this->addNameUsage($usages, $node->extends, 'class_reference', $node->getStartLine());
                }
                foreach ($node->implements as $interface) {
                    $this->addNameUsage($usages, $interface, 'class_reference', $node->getStartLine());
                }
            } elseif ($node instanceof Stmt\Interface_) {
                foreach ($node->extends as $interface) {
                    $this->addNameUsage($usages, $interface, 'class_reference', $node->getStartLine());
                }
            } elseif ($node instanceof Stmt\Enum_) {
                foreach ($node->implements as $interface) {
                    $this->addNameUsage($usages, $interface, 'class_reference', $node->getStartLine());
                }
            } elseif ($node instanceof Stmt\TraitUse) {
                foreach ($node->traits as $trait) {
                    $this->addNameUsage($usages, $trait, 'trait_reference', $node->getStartLine());
                }
            } elseif ($node instanceof Node\Attribute) {
                $this->addNameUsage($usages, $node->name, 'attribute', $node->getStartLine());
            } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
                $this->addNameUsage($usages, $node->name, 'function_call', $node->getStartLine());
            }
        }

        return $usages;
    }

    /** @param list<array{symbol: string, usage_type: string, line: int}> $usages */
    private function addNameUsage(array &$usages, Name $name, string $usageType, int $line): void
    {
        $symbol = (string) $name;
        if (in_array(strtolower($symbol), ['self', 'static', 'parent'], true)) {
            return;
        }

        $this->addUsage($usages, $symbol, $usageType, $line);
    }

    /** @param list<array{symbol: string, usage_type: string, line: int}> $usages */
    private function addUsage(array &$usages, string $symbol, string $usageType, int $line): void
    {
        if ($symbol === '') {
            return;
        }

        $usages[] = [
            'symbol' => ltrim($symbol, '\\'),
            'usage_type' => $usageType,
            'line' => $line,
        ];
    }

    private function importUsageType(int $type): string
    {
        if ($type === Stmt\Use_::TYPE_FUNCTION) {
            return 'function_import';
        }

        if ($type === Stmt\Use_::TYPE_CONSTANT) {
            return 'constant_import';
        }

        return 'namespace_import';
    }

    /** @param list<Evidence> $evidence */
    private function nextEvidenceId(array $evidence): string
    {
        $ids = array_fill_keys(array_map(static fn (Evidence $item): string => $item->id, $evidence), true);
        $index = count($evidence) + 1;

        do {
            $id = 'source-' . $index;
            ++$index;
        } while (isset($ids[$id]));

        return $id;
    }

    private function createParser(): Parser
    {
        $factory = new ParserFactory();
        $factoryReflection = new \ReflectionObject($factory);

        if ($factoryReflection->hasMethod('createForNewestSupportedVersion')) {
            $parser = $factoryReflection->getMethod('createForNewestSupportedVersion')->invoke($factory);
        } else {
            $legacyKind = constant(ParserFactory::class . '::PREFER_PHP7');
            $parser = $factoryReflection->getMethod('create')->invoke($factory, $legacyKind);
        }

        if (!$parser instanceof Parser) {
            throw new \LogicException('PHP parser factory returned an unsupported parser instance.');
        }

        return $parser;
    }

    private function relativePath(string $projectPath, string $file): string
    {
        $prefix = rtrim($projectPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strpos($file, $prefix) === 0 ? substr($file, strlen($prefix)) : $file;
    }

    private function isWithinProject(string $projectPath, string $path): bool
    {
        $projectPath = Path::canonicalize($projectPath);
        $path = Path::canonicalize($path);

        if (DIRECTORY_SEPARATOR === '\\') {
            $projectPath = strtolower($projectPath);
            $path = strtolower($path);
        }

        return $path === $projectPath || str_starts_with($path, rtrim($projectPath, '/') . '/');
    }
}
