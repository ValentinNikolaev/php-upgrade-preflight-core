<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

final class ComposerExecutionConfiguration
{
    public const MODE_COMPATIBLE = 'compatible';
    public const MODE_RESTRICTED = 'restricted';
    public const NETWORK_INHERITED = 'inherited';
    public const NETWORK_BEST_EFFORT_OFFLINE = 'best_effort_offline';
    public const ENVIRONMENT_INHERITED = 'inherited';
    public const ENVIRONMENT_SANITIZED = 'sanitized';
    public const DEFAULT_EXPECTED_VERSION = '>=2.0.0 <3.0.0';
    public const DEFAULT_SCENARIO_TIMEOUT_SECONDS = 300;
    public const DEFAULT_DIAGNOSTIC_TIMEOUT_SECONDS = 60;

    private string $executable;
    private string $expectedVersion;
    private int $scenarioTimeoutSeconds;
    private int $diagnosticTimeoutSeconds;
    private string $mode;
    private string $environmentMode;
    private string $networkPolicy;

    public function __construct(
        string $executable = 'composer',
        string $expectedVersion = self::DEFAULT_EXPECTED_VERSION,
        int $scenarioTimeoutSeconds = self::DEFAULT_SCENARIO_TIMEOUT_SECONDS,
        int $diagnosticTimeoutSeconds = self::DEFAULT_DIAGNOSTIC_TIMEOUT_SECONDS,
        string $mode = self::MODE_COMPATIBLE,
        ?string $environmentMode = null,
        ?string $networkPolicy = null
    ) {
        $executable = trim($executable);
        if ($executable === '' || preg_match('/[\x00-\x1F\x7F]/', $executable) === 1) {
            throw new \InvalidArgumentException('Composer executable must be a nonempty path or command without control characters.');
        }

        $expectedVersion = trim($expectedVersion);
        if ($expectedVersion === '') {
            throw new \InvalidArgumentException('Expected Composer version must be a valid Composer constraint.');
        }
        try {
            (new VersionParser())->parseConstraints($expectedVersion);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Expected Composer version must be a valid Composer constraint.');
        }

        if (!in_array($mode, [self::MODE_COMPATIBLE, self::MODE_RESTRICTED], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported Composer execution mode "%s".', $mode));
        }
        if ($scenarioTimeoutSeconds < 1 || $scenarioTimeoutSeconds > 3600) {
            throw new \InvalidArgumentException('Composer scenario timeout must be between 1 and 3600 seconds.');
        }
        if ($diagnosticTimeoutSeconds < 1 || $diagnosticTimeoutSeconds > 900) {
            throw new \InvalidArgumentException('Composer diagnostic timeout must be between 1 and 900 seconds.');
        }

        $expectedEnvironment = $mode === self::MODE_RESTRICTED
            ? self::ENVIRONMENT_SANITIZED
            : self::ENVIRONMENT_INHERITED;
        $expectedNetwork = $mode === self::MODE_RESTRICTED
            ? self::NETWORK_BEST_EFFORT_OFFLINE
            : self::NETWORK_INHERITED;
        $environmentMode = $environmentMode ?? $expectedEnvironment;
        $networkPolicy = $networkPolicy ?? $expectedNetwork;
        if ($environmentMode !== $expectedEnvironment || $networkPolicy !== $expectedNetwork) {
            throw new \InvalidArgumentException('Composer environment and network policies must match the selected execution mode.');
        }

        $this->executable = $executable;
        $this->expectedVersion = $expectedVersion;
        $this->scenarioTimeoutSeconds = $scenarioTimeoutSeconds;
        $this->diagnosticTimeoutSeconds = $diagnosticTimeoutSeconds;
        $this->mode = $mode;
        $this->environmentMode = $environmentMode;
        $this->networkPolicy = $networkPolicy;
    }

    public static function compatible(): self
    {
        return new self();
    }

    public static function restricted(
        string $executable = 'composer',
        string $expectedVersion = self::DEFAULT_EXPECTED_VERSION,
        int $scenarioTimeoutSeconds = self::DEFAULT_SCENARIO_TIMEOUT_SECONDS,
        int $diagnosticTimeoutSeconds = self::DEFAULT_DIAGNOSTIC_TIMEOUT_SECONDS
    ): self {
        return new self(
            $executable,
            $expectedVersion,
            $scenarioTimeoutSeconds,
            $diagnosticTimeoutSeconds,
            self::MODE_RESTRICTED
        );
    }

    public function executable(): string
    {
        return $this->executable;
    }

    public function expectedVersion(): string
    {
        return $this->expectedVersion;
    }

    public function scenarioTimeoutSeconds(): int
    {
        return $this->scenarioTimeoutSeconds;
    }

    public function diagnosticTimeoutSeconds(): int
    {
        return $this->diagnosticTimeoutSeconds;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function environmentMode(): string
    {
        return $this->environmentMode;
    }

    public function networkPolicy(): string
    {
        return $this->networkPolicy;
    }

    public function isRestricted(): bool
    {
        return $this->mode === self::MODE_RESTRICTED;
    }

    public function withScenarioTimeoutSeconds(int $scenarioTimeoutSeconds): self
    {
        return new self(
            $this->executable,
            $this->expectedVersion,
            $scenarioTimeoutSeconds,
            $this->diagnosticTimeoutSeconds,
            $this->mode,
            $this->environmentMode,
            $this->networkPolicy
        );
    }

    public function matchesVersion(?string $version): ?bool
    {
        if ($version === null || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            return null;
        }

        return Semver::satisfies($version, $this->expectedVersion);
    }

    /** @return array<string, mixed> */
    public function fingerprintData(): array
    {
        return [
            'mode' => $this->mode,
            'executable_selection' => $this->executable === 'composer' ? 'path_search' : 'explicit',
            'expected_version' => $this->expectedVersion,
            'scenario_timeout_seconds' => $this->scenarioTimeoutSeconds,
            'diagnostic_timeout_seconds' => $this->diagnosticTimeoutSeconds,
            'environment_mode' => $this->environmentMode,
            'network_policy' => $this->networkPolicy,
        ];
    }

    public function runtimeCacheKey(): string
    {
        return hash('sha256', serialize([
            'executable' => $this->executable,
            'configuration' => $this->fingerprintData(),
        ]));
    }

    /**
     * @return array<string, mixed>
     *
     * This data is an input to a digest and must never be included directly in reports.
     */
    public function stateFingerprintData(): array
    {
        $data = $this->fingerprintData();
        if ($this->executable !== 'composer') {
            $data['executable_identity_sha256'] = hash('sha256', $this->executable);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function provenance(?string $composerVersion): array
    {
        return [
            'mode' => $this->mode,
            'composer_version' => $composerVersion,
            'expected_version' => $this->expectedVersion,
            'version_matches_expectation' => $this->matchesVersion($composerVersion),
            'executable_selection' => $this->executable === 'composer' ? 'path_search' : 'explicit',
            'scenario_timeout_seconds' => $this->scenarioTimeoutSeconds,
            'diagnostic_timeout_seconds' => $this->diagnosticTimeoutSeconds,
            'environment_mode' => $this->environmentMode,
            'network_policy' => $this->networkPolicy,
            'repository_source_mode' => $this->isRestricted() ? 'project_only' : 'project_and_global',
            'composer_home' => $this->isRestricted() ? 'analyzer_owned' : 'inherited',
            'global_configuration_inherited' => !$this->isRestricted(),
            'credentials_may_be_inherited' => !$this->isRestricted(),
            'offline_requested' => $this->isRestricted(),
            'scripts_enabled' => false,
            'plugins_enabled' => false,
            'installation_enabled' => false,
            'audit_enabled' => false,
            'interaction_enabled' => false,
            'progress_enabled' => false,
            'process_os_isolation' => false,
        ];
    }
}
