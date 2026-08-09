<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class PlatformProvenance
{
    private TargetPlatform $platform;

    public function __construct(UpgradeRequest $request, ProjectState $project, ?TargetPlatform $platform = null)
    {
        $this->platform = $platform ?? TargetPlatform::fromRequest($request, $project);
    }

    public function analyzerPhp(): string
    {
        return $this->platform->analyzerPhp();
    }

    public function currentPhp(): ?string
    {
        return $this->platform->currentPhp();
    }

    public function currentPhpSource(): string
    {
        return $this->platform->currentPhpProvenance();
    }

    public function targetPhp(): ?string
    {
        return $this->platform->targetPhp();
    }

    public function targetPhpSource(): string
    {
        return $this->platform->targetPhpProvenance();
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
        $assumptions = $this->platform->extensionAssumptions();

        return [
            'analyzer' => [
                'php_version' => $this->platform->analyzerPhp(),
                'provenance' => 'runtime',
            ],
            'current_php' => [
                'version' => $this->platform->currentPhp(),
                'provenance' => $this->platform->currentPhpProvenance(),
            ],
            'target_php' => [
                'version' => $this->platform->targetPhp(),
                'provenance' => $this->platform->targetPhpProvenance(),
            ],
            'extensions' => [
                'provenance' => $assumptions !== [] ? 'mixed' : 'analyzer_runtime',
                'explicitly_modeled' => $assumptions !== [],
                'completeness' => $assumptions !== [] ? 'partial' : 'none',
                'unmodeled_provenance' => 'analyzer_runtime',
                'assumptions' => array_map(
                    static fn (ExtensionAssumption $assumption): array => $assumption->toArray(),
                    $assumptions
                ),
            ],
        ];
    }

    /** @return list<string> */
    public function uncertainties(): array
    {
        $uncertainties = [];
        if ($this->platform->extensionAssumptions() === []) {
            $uncertainties[] =
                'Composer extension checks used the analyzer runtime because no complete explicit extension platform was supplied.';
        } else {
            $uncertainties[] =
                'Composer modeled only the listed extension assumptions; every unlisted extension still came from the analyzer runtime.';
        }

        foreach ($this->platform->presenceOnlyExtensions() as $name) {
            $uncertainties[] = sprintf(
                'Extension %s was assumed present without a version; Composer used a conservative presence-only sentinel, so exact extension version compatibility remains uncertain and related solver failures are advisory rather than reproducible blockers.',
                $name
            );
        }

        return $uncertainties;
    }
}
