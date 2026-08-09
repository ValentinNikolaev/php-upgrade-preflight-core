<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class TargetPlatform
{
    private const PRESENCE_ONLY_COMPOSER_VERSION = '0';

    private string $analyzerPhp;
    private ?string $currentPhp;
    private string $currentPhpProvenance;
    private ?string $targetPhp;
    private string $targetPhpProvenance;
    /** @var list<HostExtension> */
    private array $hostExtensions;
    /** @var list<ExtensionAssumption> */
    private array $extensionAssumptions;

    /**
     * @param list<HostExtension> $hostExtensions
     * @param list<ExtensionAssumption> $extensionAssumptions
     */
    private function __construct(
        string $analyzerPhp,
        ?string $currentPhp,
        string $currentPhpProvenance,
        ?string $targetPhp,
        string $targetPhpProvenance,
        array $hostExtensions,
        array $extensionAssumptions
    ) {
        $this->analyzerPhp = $analyzerPhp;
        $this->currentPhp = $currentPhp;
        $this->currentPhpProvenance = $currentPhpProvenance;
        $this->targetPhp = $targetPhp;
        $this->targetPhpProvenance = $targetPhpProvenance;
        $this->hostExtensions = $hostExtensions;
        $this->extensionAssumptions = $extensionAssumptions;
    }

    /** @param list<HostExtension>|null $hostExtensions */
    public static function fromRequest(
        UpgradeRequest $request,
        ProjectState $project,
        ?array $hostExtensions = null,
        ?string $analyzerPhp = null
    ): self {
        $configuredPhp = $project->composerJson()->platformPhp();
        $assumptions = [];
        foreach ($project->composerJson()->configuredExtensions() as $configured) {
            $assumptions[$configured['name']] = ExtensionAssumption::fromComposerConfig(
                $configured['name'],
                $configured['state'] === ExtensionAssumption::ABSENT ? false : (string) $configured['version']
            );
        }
        foreach ($request->extensionAssumptions() as $assumption) {
            $assumptions[$assumption->name()] = $assumption;
        }
        ksort($assumptions, SORT_STRING);

        return new self(
            $analyzerPhp ?? PHP_VERSION,
            $request->fromPhp() ?? $configuredPhp,
            $request->fromPhp() !== null ? 'request' : ($configuredPhp !== null ? 'composer_config' : 'unknown'),
            $request->targetPhp(),
            $request->targetPhp() === null ? 'unknown' : 'request',
            $hostExtensions ?? self::loadedHostExtensions(),
            array_values($assumptions)
        );
    }

    public function analyzerPhp(): string
    {
        return $this->analyzerPhp;
    }

    public function currentPhp(): ?string
    {
        return $this->currentPhp;
    }

    public function currentPhpProvenance(): string
    {
        return $this->currentPhpProvenance;
    }

    public function targetPhp(): ?string
    {
        return $this->targetPhp;
    }

    public function targetPhpProvenance(): string
    {
        return $this->targetPhpProvenance;
    }

    /** @return list<HostExtension> */
    public function hostExtensions(): array
    {
        return $this->hostExtensions;
    }

    /** @return list<ExtensionAssumption> */
    public function extensionAssumptions(): array
    {
        return $this->extensionAssumptions;
    }

    /** @return array<string, string|false> */
    public function composerPlatformOverrides(): array
    {
        $overrides = [];
        foreach ($this->extensionAssumptions as $assumption) {
            if ($assumption->state() === ExtensionAssumption::ABSENT) {
                $overrides[$assumption->name()] = false;
            } else {
                $overrides[$assumption->name()] = $assumption->version() ?? self::PRESENCE_ONLY_COMPOSER_VERSION;
            }
        }

        return $overrides;
    }

    /** @return list<string> */
    public function presenceOnlyExtensions(): array
    {
        $names = [];
        foreach ($this->extensionAssumptions as $assumption) {
            if ($assumption->isPresentWithoutVersion()) {
                $names[] = $assumption->name();
            }
        }

        return $names;
    }

    public function hasAbsentExtensionAssumptions(): bool
    {
        foreach ($this->extensionAssumptions as $assumption) {
            if ($assumption->state() === ExtensionAssumption::ABSENT) {
                return true;
            }
        }

        return false;
    }

    public function isPresenceOnlyExtension(string $name): bool
    {
        $assumption = $this->extensionAssumption($name);

        return $assumption !== null && $assumption->isPresentWithoutVersion();
    }

    public function extensionAssumption(string $name): ?ExtensionAssumption
    {
        $name = strtolower($name);
        foreach ($this->extensionAssumptions as $assumption) {
            if ($assumption->name() === $name) {
                return $assumption;
            }
        }

        return null;
    }

    /** @return list<HostExtension> */
    private static function loadedHostExtensions(): array
    {
        $extensions = [];
        foreach (get_loaded_extensions() as $name) {
            $version = phpversion($name);
            $extensions[] = new HostExtension($name, is_string($version) ? $version : null);
        }
        usort($extensions, static fn (HostExtension $left, HostExtension $right): int => strcmp($left->name(), $right->name()));

        return $extensions;
    }
}
