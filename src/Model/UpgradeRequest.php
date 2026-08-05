<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class UpgradeRequest
{
    public string $projectPath;
    /** @var list<UpgradeTarget> */
    public array $targets;
    public ?string $fromPhp;
    public ?string $targetPhp;
    /** @var list<string> */
    public array $sourcePaths;
    /** @var list<string> */
    public array $frameworks;
    public string $format;
    public ?string $outputPath;
    public bool $debug;

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
        $this->targets = array_values($targets);
        $this->fromPhp = $fromPhp;
        $this->targetPhp = $targetPhp;
        $this->sourcePaths = array_values($sourcePaths);
        $this->frameworks = array_values($frameworks);
        $this->format = ReportFormat::normalize($format);
        $this->outputPath = $outputPath;
        $this->debug = $debug;
    }

    public function toArray(): array
    {
        return [
            'project_path' => $this->projectPath,
            'targets' => array_map(static fn (UpgradeTarget $target): array => $target->toArray(), $this->targets),
            'from_php' => $this->fromPhp,
            'target_php' => $this->targetPhp,
            'source_paths' => $this->sourcePaths,
            'frameworks' => $this->frameworks,
            'format' => $this->format,
            'output_path' => $this->outputPath,
        ];
    }
}
