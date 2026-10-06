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
    private ?TargetPlatformProfile $profile;
    /** @var list<TargetPlatformPackage> */
    private array $platformPackages;

    /**
     * @param list<HostExtension> $hostExtensions
     * @param list<ExtensionAssumption> $extensionAssumptions
     * @param list<TargetPlatformPackage> $platformPackages
     */
    private function __construct(
        string $analyzerPhp,
        ?string $currentPhp,
        string $currentPhpProvenance,
        ?string $targetPhp,
        string $targetPhpProvenance,
        array $hostExtensions,
        array $extensionAssumptions,
        ?TargetPlatformProfile $profile,
        array $platformPackages
    ) {
        $this->analyzerPhp = $analyzerPhp;
        $this->currentPhp = $currentPhp;
        $this->currentPhpProvenance = $currentPhpProvenance;
        $this->targetPhp = $targetPhp;
        $this->targetPhpProvenance = $targetPhpProvenance;
        $this->hostExtensions = array_values($hostExtensions);
        $this->extensionAssumptions = array_values($extensionAssumptions);
        $this->profile = $profile;
        $this->platformPackages = array_values($platformPackages);
    }

    /** @param list<HostExtension>|null $hostExtensions */
    public static function fromRequest(
        UpgradeRequest $request,
        ProjectState $project,
        ?array $hostExtensions = null,
        ?string $analyzerPhp = null
    ): self {
        $configuredPhp = $project->composerJson()->platformPhp();
        $profile = $request->targetPlatformProfile();

        /** @var array<string, TargetPlatformPackage> $packages */
        $packages = [];
        /** @var array<string, string> $higherPriority */
        $higherPriority = [];
        foreach ($project->composerJson()->configuredPlatformPackages() as $name => $value) {
            if (!self::isSupportedPackageName($name)) {
                continue;
            }
            $packages[$name] = new TargetPlatformPackage(
                $name,
                $value,
                TargetPlatformPackage::PROVENANCE_COMPOSER_CONFIG
            );
        }

        if ($profile !== null) {
            foreach ($profile->packages() as $package) {
                $packages[$package->name()] = $package;
                $higherPriority[$package->name()] = TargetPlatformPackage::PROVENANCE_PROFILE;
            }
        }

        if ($request->targetPhp() !== null && $request->targetPhpProvenance() === 'request') {
            $requestPhp = new TargetPlatformPackage(
                'php',
                $request->targetPhp(),
                TargetPlatformPackage::PROVENANCE_REQUEST
            );
            $packages['php'] = $requestPhp;
            $higherPriority['php'] = TargetPlatformPackage::PROVENANCE_REQUEST;
        }

        foreach ($request->extensionAssumptions() as $assumption) {
            $presenceOnly = $assumption->isPresentWithoutVersion();
            $value = $assumption->state() === ExtensionAssumption::ABSENT
                ? false
                : ($assumption->version() ?? self::PRESENCE_ONLY_COMPOSER_VERSION);
            $decision = $presenceOnly
                ? TargetPlatformPackage::fromPresenceOnlyExtension($assumption->name())
                : new TargetPlatformPackage(
                    $assumption->name(),
                    $value,
                    TargetPlatformPackage::PROVENANCE_REQUEST
                );
            $packages[$decision->name()] = $decision;
            $higherPriority[$decision->name()] = TargetPlatformPackage::PROVENANCE_REQUEST;
        }

        if ($profile !== null && $profile->isComplete()) {
            foreach ($packages as $name => $package) {
                if (isset($higherPriority[$name]) || $package->isToolchainBound()) {
                    continue;
                }
                $packages[$name] = new TargetPlatformPackage(
                    $name,
                    false,
                    TargetPlatformPackage::PROVENANCE_CLOSED_WORLD
                );
            }
        }
        ksort($packages, SORT_STRING);

        /** @var array<string, ExtensionAssumption> $assumptions */
        $assumptions = [];
        foreach ($packages as $package) {
            if ($package->packageClass() !== TargetPlatformPackage::CLASS_EXTENSION) {
                continue;
            }

            $requestAssumption = self::requestExtensionAssumption($request, $package->name());
            $assumptions[$package->name()] = $requestAssumption
                ?? ExtensionAssumption::fromPlatformPackage($package);
        }
        ksort($assumptions, SORT_STRING);

        return new self(
            $analyzerPhp ?? PHP_VERSION,
            $request->fromPhp() ?? $configuredPhp,
            $request->fromPhp() !== null ? 'request' : ($configuredPhp !== null ? 'composer_config' : 'unknown'),
            $request->targetPhp(),
            $request->targetPhpProvenance(),
            $hostExtensions ?? self::loadedHostExtensions(),
            array_values($assumptions),
            $profile,
            array_values($packages)
        );
    }

    public static function isSupportedPackageName(string $name): bool
    {
        return TargetPlatformPackage::isSupportedName($name);
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

    public function profile(): ?TargetPlatformProfile
    {
        return $this->profile;
    }

    public function profileDigest(): ?string
    {
        return $this->profile === null ? null : $this->profile->digest();
    }

    public function isCompleteProfile(): bool
    {
        return $this->profile !== null && $this->profile->isComplete();
    }

    /** @return list<TargetPlatformPackage> */
    public function platformPackages(): array
    {
        return $this->platformPackages;
    }

    public function platformPackage(string $name): ?TargetPlatformPackage
    {
        $name = strtolower(trim($name));
        foreach ($this->platformPackages as $package) {
            if ($package->name() === $name) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $analyzerPlatformPackages
     * @return array<string, string|false>
     */
    public function composerPlatformOverrides(array $analyzerPlatformPackages = []): array
    {
        $overrides = [];
        foreach ($this->platformPackages as $package) {
            if ($package->name() === 'php' || $package->isToolchainBound()) {
                continue;
            }
            $overrides[$package->name()] = $package->composerValue();
        }

        if ($this->isCompleteProfile()) {
            foreach ($this->hostExtensions as $extension) {
                $analyzerPlatformPackages[$extension->name()] = $extension->version() ?? '0';
            }
            foreach ($analyzerPlatformPackages as $name => $_version) {
                $name = strtolower(trim((string) $name));
                if ($name === 'php' || !self::isSupportedPackageName($name) || isset($overrides[$name])) {
                    continue;
                }
                if (in_array($name, ['composer', 'composer-plugin-api', 'composer-runtime-api'], true)) {
                    continue;
                }
                $overrides[$name] = false;
            }
        }
        ksort($overrides, SORT_STRING);

        return $overrides;
    }

    /** @return list<TargetPlatformPackage> */
    public function toolchainBoundPackages(): array
    {
        return array_values(array_filter(
            $this->platformPackages,
            static fn (TargetPlatformPackage $package): bool => $package->isToolchainBound()
        ));
    }

    public function needsToolchainValidation(): bool
    {
        return $this->isCompleteProfile() || $this->toolchainBoundPackages() !== [];
    }

    /** @param array<string, string> $actual */
    public function toolchainValidationFailure(array $actual): ?string
    {
        foreach (['composer', 'composer-plugin-api', 'composer-runtime-api'] as $name) {
            $expected = $this->platformPackage($name);
            if ($expected === null) {
                continue;
            }

            $actualVersion = $actual[$name] ?? null;
            if ($expected->isAbsent()) {
                if ($actualVersion !== null) {
                    return sprintf(
                        'Toolchain-bound platform package %s cannot be modeled absent without changing the Composer executable.',
                        $name
                    );
                }
                continue;
            }
            if ($actualVersion === null || $actualVersion !== $expected->version()) {
                return sprintf(
                    'Toolchain-bound platform package %s does not match the executing Composer toolchain and cannot be simulated safely.',
                    $name
                );
            }
        }

        return null;
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

    public function hasAbsentPlatformPackages(): bool
    {
        if ($this->isCompleteProfile()) {
            return true;
        }
        foreach ($this->platformPackages as $package) {
            if ($package->isAbsent()) {
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

    /** @return ?array<string, mixed> */
    public function profileReport(): ?array
    {
        if ($this->profile === null) {
            return null;
        }

        $report = $this->profile->toArray();
        $report['effective'] = array_map(
            static fn (TargetPlatformPackage $package): array => $package->toArray(),
            $this->platformPackages
        );

        return $report;
    }

    private static function requestExtensionAssumption(
        UpgradeRequest $request,
        string $name
    ): ?ExtensionAssumption {
        foreach ($request->extensionAssumptions() as $assumption) {
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
