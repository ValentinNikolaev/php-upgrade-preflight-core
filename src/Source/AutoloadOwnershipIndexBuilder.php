<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

use PhpParser\Error;
use PhpParser\ErrorHandler\Throwing;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpUpgradePreflight\Core\Model\PackageRef;
use PhpUpgradePreflight\Core\Model\ProjectState;
use Symfony\Component\Filesystem\Path;

final class AutoloadOwnershipIndexBuilder
{
    private const DEFAULT_MAX_EXACT_FILES = 2000;

    private Parser $parser;
    private int $maxExactFiles;
    private int $indexedFileCount = 0;
    private bool $limitReported = false;
    /** @var null|array{exact: array<string, true>, folded: array<string, true>} */
    private ?array $requestedSymbols = null;

    public function __construct(?Parser $parser = null, int $maxExactFiles = self::DEFAULT_MAX_EXACT_FILES)
    {
        if ($maxExactFiles < 1) {
            throw new \InvalidArgumentException('The exact ownership file limit must be positive.');
        }
        $this->parser = $parser ?? $this->createParser();
        $this->maxExactFiles = $maxExactFiles;
    }

    /**
     * @param list<string> $uncertainties
     * @param ?list<string> $requestedSymbols
     */
    public function build(
        ProjectState $project,
        array &$uncertainties = [],
        ?array $requestedSymbols = null
    ): SymbolOwnershipIndex {
        $this->indexedFileCount = 0;
        $this->limitReported = false;
        $this->requestedSymbols = $this->requestedSymbolMap($requestedSymbols);
        $index = new SymbolOwnershipIndex($project->composerJson()->packageName());
        $this->indexSections(
            $index,
            SymbolOwnershipIndex::ROOT_OWNER,
            $project->path(),
            $project->path(),
            [
                'autoload' => $project->composerJson()->autoload(),
                'autoload-dev' => $project->composerJson()->autoloadDev(),
            ],
            $uncertainties
        );

        $vendorBase = Path::join($project->path(), $project->composerJson()->vendorDirectory());
        $packages = $project->composerLock()->packages();
        ksort($packages, SORT_STRING);
        foreach ($packages as $package) {
            $this->indexPackage($index, $project->path(), $vendorBase, $package, $uncertainties);
        }

        $uncertainties = array_values(array_unique($uncertainties));

        return $index;
    }

    /** @param list<string> $uncertainties */
    private function indexPackage(
        SymbolOwnershipIndex $index,
        string $projectPath,
        string $vendorBase,
        PackageRef $package,
        array &$uncertainties
    ): void {
        $packageBase = Path::join($vendorBase, $package->name());
        $this->indexSections(
            $index,
            $package->name(),
            $packageBase,
            $projectPath,
            ['autoload' => $package->autoload()],
            $uncertainties
        );
    }

    /**
     * @param array<string, array<string, mixed>> $sections
     * @param list<string> $uncertainties
     */
    private function indexSections(
        SymbolOwnershipIndex $index,
        string $owner,
        string $basePath,
        string $projectPath,
        array $sections,
        array &$uncertainties
    ): void {
        foreach ($sections as $sectionName => $autoload) {
            if ($autoload === []) {
                continue;
            }

            foreach (['psr-4', 'psr-0'] as $mappingType) {
                $mappings = $autoload[$mappingType] ?? [];
                if (!is_array($mappings)) {
                    $uncertainties[] = sprintf('%s %s.%s metadata is not a static map and could not be indexed.', $this->ownerLabel($owner), $sectionName, $mappingType);
                    continue;
                }
                foreach ($mappings as $prefix => $paths) {
                    if (!is_string($prefix) || !$this->staticPaths($paths)) {
                        $uncertainties[] = sprintf('%s %s.%s contains a dynamic or unsupported mapping and could not be indexed completely.', $this->ownerLabel($owner), $sectionName, $mappingType);
                        continue;
                    }
                    $index->addPrefix($prefix, $owner, $mappingType);
                }
            }

            if ($this->requestedSymbols !== null && $this->requestedSymbols['exact'] === []) {
                continue;
            }

            $exclusions = $this->stringList($autoload['exclude-from-classmap'] ?? [], $owner, $sectionName . '.exclude-from-classmap', $uncertainties);
            foreach (['classmap', 'files'] as $mappingType) {
                if (!array_key_exists($mappingType, $autoload)) {
                    continue;
                }
                $paths = $this->stringList($autoload[$mappingType], $owner, $sectionName . '.' . $mappingType, $uncertainties);
                foreach ($paths as $path) {
                    $this->indexStaticPath($index, $owner, $mappingType, $basePath, $projectPath, $path, $exclusions, $uncertainties);
                }
            }
        }
    }

    /** @param mixed $paths */
    private function staticPaths($paths): bool
    {
        if (is_string($paths)) {
            return trim($paths) !== '';
        }
        if (!is_array($paths) || $paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     * @param list<string> $uncertainties
     * @return list<string>
     */
    private function stringList($value, string $owner, string $mapping, array &$uncertainties): array
    {
        if (!is_array($value)) {
            $uncertainties[] = sprintf('%s %s metadata is dynamic or unsupported and could not be indexed.', $this->ownerLabel($owner), $mapping);

            return [];
        }

        $paths = [];
        foreach ($value as $path) {
            if (!is_string($path) || trim($path) === '') {
                $uncertainties[] = sprintf('%s %s contains a dynamic or unsupported entry and could not be indexed completely.', $this->ownerLabel($owner), $mapping);
                continue;
            }
            $paths[] = trim($path);
        }

        sort($paths, SORT_STRING);

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $exclusions
     * @param list<string> $uncertainties
     */
    private function indexStaticPath(
        SymbolOwnershipIndex $index,
        string $owner,
        string $mappingType,
        string $basePath,
        string $projectPath,
        string $path,
        array $exclusions,
        array &$uncertainties
    ): void {
        if (strpbrk($path, '*?[]{}') !== false) {
            $uncertainties[] = sprintf('%s %s mapping uses an unsupported dynamic path.', $this->ownerLabel($owner), $mappingType);

            return;
        }

        $fullPath = Path::canonicalize(Path::join($basePath, $path));
        $resolved = realpath($fullPath);
        if ($resolved === false) {
            $uncertainties[] = sprintf('%s %s mapping was unavailable, so its symbols could not be indexed.', $this->ownerLabel($owner), $mappingType);

            return;
        }
        $resolved = Path::canonicalize($resolved);
        if (!$this->isWithin($projectPath, $resolved)) {
            $uncertainties[] = sprintf('%s %s mapping resolves outside the analyzed project and was not indexed.', $this->ownerLabel($owner), $mappingType);

            return;
        }

        if ($this->indexedFileCount >= $this->maxExactFiles) {
            $this->reportLimit($uncertainties);

            return;
        }

        $files = is_file($resolved)
            ? [$resolved]
            : $this->phpFiles($resolved, $this->maxExactFiles - $this->indexedFileCount);
        if ($files === null) {
            $this->indexedFileCount = $this->maxExactFiles;
            $this->reportLimit($uncertainties);

            return;
        }
        foreach ($files as $file) {
            if (!$this->isWithin($projectPath, $file)) {
                $uncertainties[] = sprintf('%s %s mapping contains a link outside the analyzed project and was not indexed completely.', $this->ownerLabel($owner), $mappingType);
                continue;
            }
            $relativeToBase = '/' . ltrim(str_replace('\\', '/', Path::makeRelative($file, Path::canonicalize($basePath))), '/');
            if ($mappingType === 'classmap' && $this->isExcluded($relativeToBase, $exclusions)) {
                continue;
            }
            ++$this->indexedFileCount;
            $this->indexDeclarations($index, $owner, $mappingType, $projectPath, $file, $uncertainties);
        }
    }

    /** @return ?list<string> */
    private function phpFiles(string $directory, int $limit): ?array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'inc'], true)) {
                $resolved = realpath($file->getPathname());
                if ($resolved !== false) {
                    $files[] = Path::canonicalize($resolved);
                    if (count($files) > $limit) {
                        return null;
                    }
                }
            }
        }
        sort($files, SORT_STRING);

        return array_values(array_unique($files));
    }

    /** @param list<string> $exclusions */
    private function isExcluded(string $path, array $exclusions): bool
    {
        foreach ($exclusions as $pattern) {
            $pattern = '/' . ltrim(str_replace('\\', '/', $pattern), '/');
            $pattern = rtrim($pattern, '/');
            $quoted = preg_quote($pattern, '~');
            $quoted = str_replace(['\\*\\*', '\\*'], ['.*', '[^/]*'], $quoted);
            if (preg_match('~^' . $quoted . '(?:$|/)~i', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $uncertainties */
    private function indexDeclarations(
        SymbolOwnershipIndex $index,
        string $owner,
        string $mappingType,
        string $projectPath,
        string $file,
        array &$uncertainties
    ): void {
        $contents = @file_get_contents($file);
        $relative = str_replace('\\', '/', Path::makeRelative($file, Path::canonicalize($projectPath)));
        if ($contents === false) {
            $uncertainties[] = sprintf('%s %s file "%s" could not be read for symbol ownership.', $this->ownerLabel($owner), $mappingType, $relative);

            return;
        }

        try {
            $nodes = $this->parser->parse($contents, new Throwing()) ?? [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(new Throwing()));
            $nodes = $traverser->traverse($nodes);
            $visitor = new SymbolDeclarationVisitor();
            $declarationTraverser = new NodeTraverser();
            $declarationTraverser->addVisitor($visitor);
            $declarationTraverser->traverse($nodes);
        } catch (Error $exception) {
            $uncertainties[] = sprintf('%s %s file "%s" could not be parsed for symbol ownership.', $this->ownerLabel($owner), $mappingType, $relative);

            return;
        }

        foreach ($visitor->declarations() as $declaration) {
            if (!$this->isRequestedSymbol($declaration['symbol'], $declaration['type'])) {
                continue;
            }
            $index->addExact($declaration['symbol'], $owner, $mappingType, $declaration['type']);
        }
        if ($visitor->hasDynamicLoader()) {
            $uncertainties[] = sprintf('%s %s file "%s" registers or generates symbols dynamically; static ownership may be incomplete.', $this->ownerLabel($owner), $mappingType, $relative);
        }
    }

    private function isWithin(string $projectPath, string $path): bool
    {
        $projectPath = rtrim(Path::canonicalize((string) realpath($projectPath)), '/');
        $path = Path::canonicalize($path);
        if (DIRECTORY_SEPARATOR === '\\') {
            $projectPath = strtolower($projectPath);
            $path = strtolower($path);
        }

        return $path === $projectPath || str_starts_with($path, $projectPath . '/');
    }

    private function ownerLabel(string $owner): string
    {
        return $owner === SymbolOwnershipIndex::ROOT_OWNER ? 'Root package' : sprintf('Locked package "%s"', $owner);
    }

    /**
     * @param ?list<string> $symbols
     * @return null|array{exact: array<string, true>, folded: array<string, true>}
     */
    private function requestedSymbolMap(?array $symbols): ?array
    {
        if ($symbols === null) {
            return null;
        }

        $exact = [];
        $folded = [];
        foreach ($symbols as $symbol) {
            $symbol = ltrim(trim($symbol), '\\');
            if ($symbol === '') {
                continue;
            }
            $exact[$symbol] = true;
            $folded[strtolower($symbol)] = true;
        }

        return ['exact' => $exact, 'folded' => $folded];
    }

    private function isRequestedSymbol(string $symbol, string $type): bool
    {
        if ($this->requestedSymbols === null) {
            return true;
        }

        return $type === 'constant'
            ? isset($this->requestedSymbols['exact'][$symbol])
            : isset($this->requestedSymbols['folded'][strtolower($symbol)]);
    }

    /** @param list<string> $uncertainties */
    private function reportLimit(array &$uncertainties): void
    {
        if ($this->limitReported) {
            return;
        }

        $this->limitReported = true;
        $uncertainties[] = sprintf(
            'Static autoload ownership indexing reached the %d-file safety limit; remaining classmap/files mappings were not indexed.',
            $this->maxExactFiles
        );
    }

    private function createParser(): Parser
    {
        $factory = new ParserFactory();
        $reflection = new \ReflectionObject($factory);
        $parser = $reflection->hasMethod('createForNewestSupportedVersion')
            ? $reflection->getMethod('createForNewestSupportedVersion')->invoke($factory)
            : $reflection->getMethod('create')->invoke($factory, constant(ParserFactory::class . '::PREFER_PHP7'));

        if (!$parser instanceof Parser) {
            throw new \LogicException('PHP parser factory returned an unsupported parser instance.');
        }

        return $parser;
    }
}
