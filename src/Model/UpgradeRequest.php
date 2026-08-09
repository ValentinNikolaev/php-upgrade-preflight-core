<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class UpgradeRequest
{
    private string $projectPath;
    private UpgradeTargetSet $targets;
    private ?string $fromPhp;
    private ?string $targetPhp;
    /** @var list<string> */
    private array $sourcePaths;
    /** @var list<string> */
    private array $frameworks;
    private string $format;
    private ?string $outputPath;
    private bool $debug;
    private ExtensionAssumptionSet $extensionAssumptions;

    /**
     * @param list<UpgradeTarget> $targets
     * @param list<string> $sourcePaths
     * @param list<string> $frameworks
     * @param list<ExtensionAssumption> $extensionAssumptions
     */
    public function __construct(
        string $projectPath,
        array $targets,
        ?string $fromPhp = null,
        ?string $targetPhp = null,
        array $sourcePaths = [],
        array $frameworks = [],
        string $format = ReportFormat::JSON,
        ?string $outputPath = null,
        bool $debug = false,
        array $extensionAssumptions = []
    ) {
        $resolved = realpath($projectPath);

        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException(sprintf('Project path "%s" does not exist.', $projectPath));
        }

        $this->projectPath = $resolved;
        $this->targets = new UpgradeTargetSet($targets, $targetPhp);
        $this->fromPhp = $this->validateCurrentPhp($fromPhp);
        $this->targetPhp = $this->targets->targetPhp();
        $this->sourcePaths = $this->normalizeSourcePaths($sourcePaths);
        $this->frameworks = $this->normalizeFrameworks($frameworks);
        $this->format = ReportFormat::normalize($format);
        $this->outputPath = $outputPath;
        $this->debug = $debug;
        $this->extensionAssumptions = new ExtensionAssumptionSet($extensionAssumptions);
    }

    public function projectPath(): string
    {
        return $this->projectPath;
    }

    public function targets(): UpgradeTargetSet
    {
        return $this->targets;
    }

    public function fromPhp(): ?string
    {
        return $this->fromPhp;
    }

    public function targetPhp(): ?string
    {
        return $this->targetPhp;
    }

    /** @return list<string> */
    public function sourcePaths(): array
    {
        return $this->sourcePaths;
    }

    /** @return list<string> */
    public function frameworks(): array
    {
        return $this->frameworks;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function outputPath(): ?string
    {
        return $this->outputPath;
    }

    public function debug(): bool
    {
        return $this->debug;
    }

    /** @return list<ExtensionAssumption> */
    public function extensionAssumptions(): array
    {
        return $this->extensionAssumptions->all();
    }

    /** @return array{project_path: string, targets: list<array{package: string, constraint: string}>, from_php: ?string, target_php: ?string, source_paths: list<string>, frameworks: list<string>, format: string, output_path: ?string} */
    public function toArray(): array
    {
        return [
            'project_path' => $this->projectPath,
            'targets' => $this->targets->toArray(),
            'from_php' => $this->fromPhp,
            'target_php' => $this->targetPhp,
            'source_paths' => $this->sourcePaths,
            'frameworks' => $this->frameworks,
            'format' => $this->format,
            'output_path' => $this->outputPath,
        ];
    }

    /** @param list<string> $frameworks @return list<string> */
    private function normalizeFrameworks(array $frameworks): array
    {
        $normalized = [];

        foreach ($frameworks as $index => $framework) {
            if (!is_string($framework)) {
                throw new \InvalidArgumentException(sprintf('Framework at index %d must be a string.', $index));
            }

            $framework = strtolower(trim($framework));
            if ($framework === '') {
                throw new \InvalidArgumentException(sprintf('Framework at index %d must not be empty.', $index));
            }

            $normalized[$framework] = true;
        }

        $frameworks = array_keys($normalized);
        sort($frameworks, SORT_STRING);

        return $frameworks;
    }

    private function validateCurrentPhp(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $version = trim($version);
        if (!preg_match('/^v?\d+(?:\.\d+)?(?:\.\d+)?$/i', $version)) {
            throw new \InvalidArgumentException(sprintf(
                'Current PHP version "%s" must be an exact major, major.minor, or major.minor.patch version.',
                $version
            ));
        }

        return $version;
    }

    /** @param list<string> $sourcePaths @return list<string> */
    private function normalizeSourcePaths(array $sourcePaths): array
    {
        $normalized = [];

        foreach ($sourcePaths as $index => $sourcePath) {
            if (!is_string($sourcePath) || trim($sourcePath) === '') {
                throw new \InvalidArgumentException(sprintf('Source path at index %d must not be empty.', $index));
            }

            $sourcePath = trim($sourcePath);
            $candidate = $this->isAbsolutePath($sourcePath)
                ? $sourcePath
                : $this->projectPath . DIRECTORY_SEPARATOR . $sourcePath;
            $resolved = realpath($candidate);

            if ($resolved === false || (!is_file($resolved) && !is_dir($resolved))) {
                throw new \InvalidArgumentException(sprintf('Source path "%s" does not exist.', $sourcePath));
            }

            if (!$this->isWithinProject($resolved)) {
                throw new \InvalidArgumentException(sprintf('Source path "%s" must resolve inside the analyzed project.', $sourcePath));
            }

            $relative = ltrim(str_replace('\\', '/', substr($resolved, strlen($this->projectPath))), '/');
            $normalized[$relative === '' ? '.' : $relative] = true;
        }

        return array_keys($normalized);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function isWithinProject(string $path): bool
    {
        $projectPath = str_replace('\\', '/', $this->projectPath);
        $path = str_replace('\\', '/', $path);

        if (DIRECTORY_SEPARATOR === '\\') {
            $projectPath = strtolower($projectPath);
            $path = strtolower($path);
        }

        return $path === $projectPath || str_starts_with($path, rtrim($projectPath, '/') . '/');
    }
}
