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
     *   extensions: array{provenance: string, explicitly_modeled: bool, completeness: string, unmodeled_provenance: ?string, assumptions: list<array{name: string, state: string, version: ?string, provenance: string}>},
     *   profile: ?array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        $assumptions = $this->platform->extensionAssumptions();
        $profile = $this->platform->profile();
        $completeness = $profile === null
            ? ($assumptions !== [] ? 'partial' : 'none')
            : $profile->completeness();
        $extensionProvenance = $this->extensionProvenance($assumptions, $profile);

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
                'provenance' => $extensionProvenance,
                'explicitly_modeled' => $profile !== null || $assumptions !== [],
                'completeness' => $completeness,
                'unmodeled_provenance' => $completeness === 'complete' ? null : 'analyzer_runtime',
                'assumptions' => array_map(
                    static fn (ExtensionAssumption $assumption): array => $assumption->toArray(),
                    $assumptions
                ),
            ],
            'profile' => $this->platform->profileReport(),
        ];
    }

    /** @return list<string> */
    public function uncertainties(): array
    {
        $uncertainties = [];
        $profile = $this->platform->profile();
        if ($profile !== null && $profile->isComplete()) {
            $uncertainties[] =
                'Target-platform completeness covers Composer platform package decisions only; the Composer executable, repositories, network access, and repository metadata remain separate inputs that can affect resolution.';
        } elseif ($profile !== null) {
            $uncertainties[] =
                'The target-platform profile is partial; unlisted supported platform packages still came from the analyzer runtime.';
        } elseif ($this->platform->extensionAssumptions() === []) {
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

        if ($this->platform->toolchainBoundPackages() !== []) {
            $uncertainties[] =
                'Composer, composer-plugin-api, and composer-runtime-api are toolchain-bound: declared values are accepted only when they exactly match the executing Composer inventory and are never simulated by changing the executable.';
        }

        return $uncertainties;
    }

    /**
     * @param list<ExtensionAssumption> $assumptions
     */
    private function extensionProvenance(array $assumptions, ?TargetPlatformProfile $profile): string
    {
        if ($profile === null) {
            return $assumptions !== [] ? 'mixed' : 'analyzer_runtime';
        }
        if ($assumptions === []) {
            return $profile->isComplete() ? 'profile' : 'mixed';
        }

        $sources = [];
        foreach ($assumptions as $assumption) {
            $source = $assumption->provenance() === ExtensionAssumption::CLOSED_WORLD
                ? ExtensionAssumption::PROFILE
                : $assumption->provenance();
            $sources[$source] = true;
        }

        return count($sources) === 1 ? (string) array_key_first($sources) : 'mixed';
    }
}
