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

    /**
     * @param list<UpgradeTarget> $targets
     * @param list<string> $sourcePaths
     * @param list<string> $frameworks
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
        bool $debug = false
    ) {
        $resolved = realpath($projectPath);

        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException(sprintf('Project path "%s" does not exist.', $projectPath));
        }

        $this->projectPath = $resolved;
        $this->targets = new UpgradeTargetSet($targets, $targetPhp);
        $this->fromPhp = $fromPhp;
        $this->targetPhp = $this->targets->targetPhp();
        $this->sourcePaths = array_values($sourcePaths);
        $this->frameworks = array_values($frameworks);
        $this->format = ReportFormat::normalize($format);
        $this->outputPath = $outputPath;
        $this->debug = $debug;
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
}
