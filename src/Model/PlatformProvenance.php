<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class PlatformProvenance
{
    private string $analyzerPhp;
    private ?string $currentPhp;
    private string $currentPhpSource;
    private ?string $targetPhp;
    private string $targetPhpSource;
    /** @var list<array{name: string, state: string, version: ?string, provenance: string}> */
    private array $extensionAssumptions;

    public function __construct(UpgradeRequest $request, ProjectState $project)
    {
        $configuredPhp = $project->composerJson()->platformPhp();

        $this->analyzerPhp = PHP_VERSION;
        $this->currentPhp = $request->fromPhp() ?? $configuredPhp;
        $this->currentPhpSource = $request->fromPhp() !== null
            ? 'request'
            : ($configuredPhp !== null ? 'composer_config' : 'unknown');
        $this->targetPhp = $request->targetPhp();
        $this->targetPhpSource = $request->targetPhp() === null ? 'unknown' : 'request';
        $this->extensionAssumptions = $project->composerJson()->configuredExtensions();
    }

    public function analyzerPhp(): string
    {
        return $this->analyzerPhp;
    }

    public function currentPhp(): ?string
    {
        return $this->currentPhp;
    }

    public function currentPhpSource(): string
    {
        return $this->currentPhpSource;
    }

    public function targetPhp(): ?string
    {
        return $this->targetPhp;
    }

    public function targetPhpSource(): string
    {
        return $this->targetPhpSource;
    }

    /**
     * @return array{
     *   analyzer: array{php_version: string, provenance: string},
     *   current_php: array{version: ?string, provenance: string},
     *   target_php: array{version: ?string, provenance: string},
     *   extensions: array{provenance: string, explicitly_modeled: bool, completeness: string, unmodeled_provenance: ?string, assumptions: list<array{name: string, state: string, version: ?string, provenance: string}>}
     * }
     */
    public function toArray(): array
    {
        return [
            'analyzer' => [
                'php_version' => $this->analyzerPhp,
                'provenance' => 'runtime',
            ],
            'current_php' => [
                'version' => $this->currentPhp,
                'provenance' => $this->currentPhpSource,
            ],
            'target_php' => [
                'version' => $this->targetPhp,
                'provenance' => $this->targetPhpSource,
            ],
            'extensions' => [
                'provenance' => $this->extensionAssumptions !== [] ? 'mixed' : 'analyzer_runtime',
                'explicitly_modeled' => $this->extensionAssumptions !== [],
                'completeness' => $this->extensionAssumptions !== [] ? 'partial' : 'none',
                'unmodeled_provenance' => 'analyzer_runtime',
                'assumptions' => $this->extensionAssumptions,
            ],
        ];
    }

    /** @return list<string> */
    public function uncertainties(): array
    {
        if ($this->extensionAssumptions === []) {
            return [
                'Composer extension checks used the analyzer runtime because no complete explicit extension platform was supplied.',
            ];
        }

        return [
            'Composer modeled only the listed extension assumptions; every unlisted extension still came from the analyzer runtime.',
        ];
    }
}
