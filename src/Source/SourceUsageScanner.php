<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

use PhpParser\Error;
use PhpParser\ErrorHandler\Throwing;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Framework\SourceUsageVisitorProvider;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use Symfony\Component\Filesystem\Path;

final class SourceUsageScanner
{
    private const MAX_CANONICAL_PATH_EXPANSIONS = 64;

    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? $this->createParser();
    }

    /**
     * @param list<string>               $paths
     * @param list<string>               $uncertainties
     * @param list<FrameworkIntegration> $frameworks integrations active for this project; those
     *                                               implementing SourceUsageVisitorProvider contribute
     *                                               framework-shaped inspection to every scanned file.
     *                                               An integration that fails is skipped with evidence
     *                                               instead of ending the scan
     * @return list<SourceUsage>
     */
    public function scan(
        ProjectState $project,
        array $paths,
        EvidenceLedger $evidence,
        array &$uncertainties = [],
        bool $reportMissingPaths = true,
        array $frameworks = []
    ): array {
        $usages = [];
        /** @var array<string, int> $usageIndexes */
        $usageIndexes = [];
        /** @var array<string, true> $skippedProviders */
        $skippedProviders = [];
        $files = $this->phpFiles($project->path(), $paths, $uncertainties, $reportMissingPaths);

        if ($files === []) {
            $uncertainties[] = 'No PHP source files were scanned.';
        }

        foreach ($files as $file) {
            $contents = @file_get_contents($file);
            if ($contents === false) {
                $uncertainties[] = sprintf('Source file "%s" could not be read and was not scanned.', $this->relativePath($project->path(), $file));
                continue;
            }

            $relative = $this->relativePath($project->path(), $file);
            /** @var list<array{provider: string, reason: string}> $providerFailures */
            $providerFailures = [];

            try {
                $detectedUsages = $this->extractAstUsages($contents, $relative, $frameworks, $skippedProviders, $providerFailures);
            } catch (Error $exception) {
                $id = $evidence->add('source', Evidence::E3_PROJECT_SOURCE, sprintf('Unable to parse %s.', $relative), 'high', [
                    'file' => $relative,
                    'line' => $exception->getStartLine(),
                    'error' => $exception->getMessage(),
                    'parser' => 'nikic/php-parser',
                    'failure_type' => 'parse_error',
                ])->id();
                $uncertainties[] = sprintf('Source file "%s" could not be parsed and was not scanned (%s).', $relative, $id);
                continue;
            }

            // A broken adapter degrades one report dimension; it does not end the analysis. The
            // integration stops being consulted after its first failure, so a defect costs exactly
            // one evidence record and one uncertainty however many files remain.
            foreach ($providerFailures as $providerFailure) {
                $skippedProviders[$providerFailure['provider']] = true;
                $id = $evidence->add(
                    'source-collector',
                    Evidence::E2_PACKAGE_METADATA,
                    'An active adapter failed while contributing source-usage collectors.',
                    'high',
                    [
                        'provider' => $providerFailure['provider'],
                        'reason' => $providerFailure['reason'],
                        'file' => $relative,
                    ]
                )->id();
                $uncertainties[] = sprintf(
                    'Adapter "%s" failed while inspecting source file "%s"; its contributed source usages were skipped for the rest of the scan (%s).',
                    $providerFailure['provider'],
                    $relative,
                    $id
                );
            }

            foreach ($detectedUsages as $detectedUsage) {
                $id = $evidence->add('source', Evidence::E3_PROJECT_SOURCE, sprintf('Detected %s in %s.', $detectedUsage['symbol'], $relative), 'high', [
                    'file' => $relative,
                    'line' => $detectedUsage['line'],
                    'usage_type' => $detectedUsage['usage_type'],
                ])->id();

                $usageKey = serialize([
                    $relative,
                    $detectedUsage['symbol'],
                    $detectedUsage['usage_type'],
                    $detectedUsage['line'],
                ]);
                if (isset($usageIndexes[$usageKey])) {
                    $index = $usageIndexes[$usageKey];
                    $usages[$index] = $usages[$index]->withAdditionalEvidence([$id]);

                    continue;
                }

                $usageIndexes[$usageKey] = count($usages);
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

        return array_values($usages);
    }

    /**
     * @param list<string> $paths
     * @param list<string> $uncertainties
     * @return list<string>
     */
    private function phpFiles(string $projectPath, array $paths, array &$uncertainties, bool $reportMissingPaths): array
    {
        $files = [];
        $projectPath = $this->canonicalExistingPath($projectPath);

        foreach ($paths as $path) {
            if (trim($path) === '') {
                $uncertainties[] = 'An empty source path was not scanned.';
                continue;
            }

            $relativePath = trim(str_replace('\\', '/', trim($path)), '/');
            $fullPath = Path::join($projectPath, $relativePath);
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
                $this->collectDirectoryFiles($projectPath, $resolved, $files, $uncertainties);
            } catch (\UnexpectedValueException $exception) {
                $uncertainties[] = sprintf('Source path "%s" could not be traversed: %s', $path, $exception->getMessage());
            }
        }

        $files = array_values($files);
        usort($files, static fn (string $left, string $right): int => strcmp(Path::canonicalize($left), Path::canonicalize($right)));

        return $files;
    }

    /**
     * Collects PHP files under an already-resolved, project-contained directory.
     *
     * @param array<string, string> $files
     * @param list<string>          $uncertainties
     *
     * @throws \UnexpectedValueException when the directory cannot be traversed
     */
    private function collectDirectoryFiles(string $projectPath, string $resolved, array &$files, array &$uncertainties): void
    {
        $directory = new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            fn (\SplFileInfo $entry): bool => !$entry->isDir()
                || !$this->isDefaultExcludedDirectory($resolved, $entry->getPathname())
        );
        $iterator = new \RecursiveIteratorIterator($filter);

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
    }

    /**
     * Traverses one file with the framework-neutral collector first, then with every
     * collector contributed by an active integration. Collection order is part of the
     * report contract: usages are reported in collector-registration order, so the
     * core collector's usages always precede contributed ones for the same file.
     *
     * The file is parsed once and every collector then traverses those statements in
     * its own traverser. A traverser shares one traversal-control flag across its whole
     * visitor list, so a single shared traverser would let a contributed collector
     * truncate core's inventory with DONT_TRAVERSE_CHILDREN or STOP_TRAVERSAL and make
     * the framework-neutral report depend on which adapters happen to be installed.
     *
     * Contributed inspection is also contained. A provider that throws, a yielded value
     * that is not a SourceUsageCollector, and a collector that throws while traversing
     * all cost that integration its whole contribution for this file and are reported to
     * the caller, which records the degradation and stops consulting the integration.
     *
     * @param list<FrameworkIntegration>                    $frameworks
     * @param array<string, true>                           $skippedProviders providers disabled by an earlier failure
     * @param list<array{provider: string, reason: string}> $providerFailures failures observed for this file
     * @return list<array{symbol: string, usage_type: string, line: int}>
     */
    private function extractAstUsages(
        string $contents,
        string $file,
        array $frameworks,
        array $skippedProviders,
        array &$providerFailures
    ): array {
        $nodes = $this->parser->parse($contents, new Throwing()) ?? [];
        $markerTraverser = new NodeTraverser();
        $markerTraverser->addVisitor(new ExplicitFullyQualifiedNameVisitor());
        $nodes = $markerTraverser->traverse($nodes);

        $resolverTraverser = new NodeTraverser();
        $resolverTraverser->addVisitor(new NameResolver(new Throwing()));
        $nodes = $resolverTraverser->traverse($nodes);

        $usages = $this->collectUsages(new SourceUsageVisitor(), $nodes);

        foreach ($frameworks as $framework) {
            if (!$framework instanceof SourceUsageVisitorProvider) {
                continue;
            }

            $provider = $this->providerName($framework);
            if (isset($skippedProviders[$provider])) {
                continue;
            }

            /** @var list<list<array{symbol: string, usage_type: string, line: int}>> $contributed */
            $contributed = [];
            $reason = null;

            try {
                foreach ($framework->sourceUsageVisitors($file) as $collector) {
                    if (!$collector instanceof SourceUsageCollector) {
                        $reason = 'invalid_collector';
                        break;
                    }

                    try {
                        $contributed[] = $this->collectUsages($collector, $nodes);
                    } catch (\Throwable) {
                        $reason = 'collector_failure';
                        break;
                    }
                }
            } catch (\Throwable) {
                $reason = 'provider_failure';
            }

            if ($reason !== null) {
                $providerFailures[] = ['provider' => $provider, 'reason' => $reason];

                continue;
            }

            foreach ($contributed as $contributedUsages) {
                foreach ($contributedUsages as $usage) {
                    $usages[] = $usage;
                }
            }
        }

        return $usages;
    }

    /**
     * @param array<Node> $nodes already parsed and name-resolved statements, shared by every collector
     * @return list<array{symbol: string, usage_type: string, line: int}>
     */
    private function collectUsages(SourceUsageCollector $collector, array $nodes): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($nodes);

        return $collector->usages();
    }

    /**
     * Names an integration for degradation evidence without trusting adapter code to
     * answer; a provider that cannot report its own name is identified by its class.
     */
    private function providerName(FrameworkIntegration $framework): string
    {
        try {
            $name = trim($framework->name());
        } catch (\Throwable) {
            return get_class($framework);
        }

        return $name === '' ? get_class($framework) : $name;
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
        $projectPath = rtrim($this->canonicalExistingPath($projectPath), '/');
        $file = Path::canonicalize($file);
        $comparisonProjectPath = $projectPath;
        $comparisonFile = $file;

        if (DIRECTORY_SEPARATOR === '\\') {
            $comparisonProjectPath = strtolower($comparisonProjectPath);
            $comparisonFile = strtolower($comparisonFile);
        }

        $prefix = $comparisonProjectPath . '/';

        return str_starts_with($comparisonFile, $prefix) ? substr($file, strlen($prefix)) : $file;
    }

    private function isWithinProject(string $projectPath, string $path): bool
    {
        $projectPath = $this->canonicalExistingPath($projectPath);
        $path = $this->canonicalExistingPath($path);

        if (DIRECTORY_SEPARATOR === '\\') {
            $projectPath = strtolower($projectPath);
            $path = strtolower($path);
        }

        return $path === $projectPath || str_starts_with($path, rtrim($projectPath, '/') . '/');
    }

    private function canonicalExistingPath(string $path): string
    {
        $resolved = Path::canonicalize($path);
        $seen = [];

        for ($depth = 0; $depth < self::MAX_CANONICAL_PATH_EXPANSIONS; ++$depth) {
            $comparison = DIRECTORY_SEPARATOR === '\\' ? strtolower($resolved) : $resolved;
            if (isset($seen[$comparison])) {
                throw new \RuntimeException(sprintf('Unable to canonicalize cyclic path "%s".', $path));
            }

            $seen[$comparison] = true;
            $expanded = realpath($resolved);
            if ($expanded === false) {
                return $resolved;
            }

            $expanded = Path::canonicalize($expanded);
            $isStable = DIRECTORY_SEPARATOR === '\\'
                ? strcasecmp($resolved, $expanded) === 0
                : $resolved === $expanded;
            if ($isStable) {
                return $expanded;
            }

            $resolved = $expanded;
        }

        throw new \RuntimeException(sprintf(
            'Unable to canonicalize path "%s" after %d expansions.',
            $path,
            self::MAX_CANONICAL_PATH_EXPANSIONS
        ));
    }

    private function isDefaultExcludedDirectory(string $scanRoot, string $path): bool
    {
        $relative = strtolower(str_replace('\\', '/', $this->relativePath($scanRoot, $path)));
        $segments = array_values(array_filter(explode('/', trim($relative, '/')), static fn (string $segment): bool => $segment !== ''));

        foreach ($segments as $segment) {
            if (in_array($segment, ['.git', '.cache', 'generated', 'node_modules', 'vendor'], true)) {
                return true;
            }
        }

        for ($index = 0, $last = count($segments) - 1; $index < $last; ++$index) {
            $pair = $segments[$index] . '/' . $segments[$index + 1];
            if (in_array($pair, ['bootstrap/cache', 'storage/framework', 'var/cache'], true)) {
                return true;
            }
        }

        return false;
    }
}
