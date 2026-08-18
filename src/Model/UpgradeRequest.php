<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class UpgradeRequest
{
    private string $projectPath;
    private UpgradeTargetSet $targets;
    private ?string $fromPhp;
    private ?string $targetPhp;
    private string $targetPhpProvenance;
    /** @var list<string> */
    private array $sourcePaths;
    /** @var list<string> */
    private array $frameworks;
    private string $format;
    private ?string $outputPath;
    private bool $debug;
    private ExtensionAssumptionSet $extensionAssumptions;
    private ?TargetPlatformProfile $targetPlatformProfile;
    private ComposerExecutionConfiguration $composerExecution;

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
        array $extensionAssumptions = [],
        ?TargetPlatformProfile $targetPlatformProfile = null,
        ?ComposerExecutionConfiguration $composerExecution = null
    ) {
        $resolved = realpath($projectPath);

        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Project path does not exist.');
        }

        $this->projectPath = $resolved;
        $profilePhp = $targetPlatformProfile === null ? null : $targetPlatformProfile->package('php');
        $profilePhpVersion = $profilePhp === null ? null : $profilePhp->version();
        $requestContainsPhp = $targetPhp !== null;
        foreach ($targets as $target) {
            if ($target instanceof UpgradeTarget && strtolower(trim($target->package())) === 'php') {
                $requestContainsPhp = true;
            }
        }
        $this->targets = new UpgradeTargetSet($targets, $this->mergeTargetPhp($targetPhp, $profilePhpVersion));
        $this->fromPhp = $this->validateCurrentPhp($fromPhp);
        $this->targetPhp = $this->targets->targetPhp();
        $this->targetPhpProvenance = $this->targetPhp === null
            ? 'unknown'
            : ($requestContainsPhp ? 'request' : 'profile');
        $this->sourcePaths = $this->normalizeSourcePaths($sourcePaths);
        $this->frameworks = $this->normalizeFrameworks($frameworks);
        $this->format = ReportFormat::normalize($format);
        $this->outputPath = $outputPath;
        $this->debug = $debug;
        $this->extensionAssumptions = new ExtensionAssumptionSet($extensionAssumptions);
        $this->targetPlatformProfile = $targetPlatformProfile;
        $this->composerExecution = $composerExecution ?? ComposerExecutionConfiguration::compatible();
        $this->assertTargetPlatformProfileCompatibility();
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

    public function targetPhpProvenance(): string
    {
        return $this->targetPhpProvenance;
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

    public function targetPlatformProfile(): ?TargetPlatformProfile
    {
        return $this->targetPlatformProfile;
    }

    public function composerExecution(): ComposerExecutionConfiguration
    {
        return $this->composerExecution;
    }

    public function withComposerExecution(ComposerExecutionConfiguration $composerExecution): self
    {
        $request = clone $this;
        $request->composerExecution = $composerExecution;

        return $request;
    }

    /** @return array<string, mixed> */
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
            'target_platform_profile' => $this->targetPlatformProfile === null
                ? null
                : $this->targetPlatformProfile->summary(),
            'composer_execution' => $this->composerExecution->fingerprintData(),
        ];
    }

    private function mergeTargetPhp(?string $requested, ?string $profile): ?string
    {
        if ($profile === null) {
            return $requested;
        }
        if ($requested === null) {
            return $profile;
        }

        $requestedTarget = new UpgradeTargetSet([], $requested);
        if ($requestedTarget->targetPhp() !== $profile) {
            throw new \InvalidArgumentException('Target PHP contradicts the target platform profile.');
        }

        return $profile;
    }

    private function assertTargetPlatformProfileCompatibility(): void
    {
        if ($this->targetPlatformProfile === null) {
            return;
        }

        foreach ($this->extensionAssumptions->all() as $assumption) {
            $profileDecision = $this->targetPlatformProfile->package($assumption->name());
            if ($assumption->isPresentWithoutVersion()) {
                if ($this->targetPlatformProfile->isComplete()) {
                    throw new \InvalidArgumentException(
                        'A complete target platform profile cannot be combined with a presence-only extension assumption.'
                    );
                }
                if ($profileDecision !== null && $profileDecision->isAbsent()) {
                    throw new \InvalidArgumentException(sprintf(
                        'Presence-only request platform package %s contradicts its absence in the target platform profile.',
                        $assumption->name()
                    ));
                }

                continue;
            }

            if ($profileDecision === null) {
                continue;
            }

            $requestValue = $assumption->state() === ExtensionAssumption::ABSENT
                ? false
                : $assumption->version();
            if ($profileDecision->composerValue() !== $requestValue) {
                throw new \InvalidArgumentException(sprintf(
                    'Request platform package %s contradicts the target platform profile.',
                    $assumption->name()
                ));
            }
        }
    }

    /**
     * @param list<string> $frameworks
     * @return list<string>
     */
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

    /**
     * @param list<string> $sourcePaths
     * @return list<string>
     */
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
                throw new \InvalidArgumentException(sprintf('Source path at index %d does not exist.', $index));
            }

            if (!$this->isWithinProject($resolved)) {
                throw new \InvalidArgumentException(sprintf(
                    'Source path at index %d must resolve inside the analyzed project.',
                    $index
                ));
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
