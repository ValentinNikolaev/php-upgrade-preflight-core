<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

use PhpUpgradePreflight\Core\Support\PathExposurePolicy;

final class ProjectStateFingerprint
{
    private string $manifestSha256;
    private string $lockSha256;
    private string $platformSha256;
    private string $executionPolicySha256;
    private string $stateSha256;

    private function __construct(
        string $manifestSha256,
        string $lockSha256,
        string $platformSha256,
        string $executionPolicySha256,
        string $stateSha256
    ) {
        $this->manifestSha256 = $manifestSha256;
        $this->lockSha256 = $lockSha256;
        $this->platformSha256 = $platformSha256;
        $this->executionPolicySha256 = $executionPolicySha256;
        $this->stateSha256 = $stateSha256;
    }

    /** @param array<string, mixed> $executionPolicy */
    public static function fromState(
        ProjectState $state,
        TargetPlatform $platform,
        string $analysisPhp,
        array $executionPolicy
    ): self {
        $repositoryPaths = PathExposurePolicy::localRepositoryPaths(
            $state->composerJson()->data(),
            $state->path()
        );
        $sanitized = PathExposurePolicy::sanitizeCanonicalReport([
            'manifest' => $state->composerJson()->data(),
            'lock' => $state->composerLock()->data(),
        ], $state->path(), null, $repositoryPaths);
        $lockData = $sanitized['lock'];
        $manifest = self::digest(self::portable($sanitized['manifest']));
        $lock = self::digest(self::portable(
            is_array($lockData) ? self::withoutDerivedLockFields($lockData) : $lockData
        ));
        $effectivePlatform = self::digest([
            'php' => $analysisPhp,
            'closed_world' => $platform->isCompleteProfile(),
            'explicit_decisions' => self::semanticPlatformDecisions($platform),
        ]);
        $policy = self::digest($executionPolicy);

        return new self(
            $manifest,
            $lock,
            $effectivePlatform,
            $policy,
            self::digest([
                'manifest' => $manifest,
                'lock' => $lock,
                'platform' => $effectivePlatform,
                'execution_policy' => $policy,
            ])
        );
    }

    public function stateSha256(): string
    {
        return $this->stateSha256;
    }

    public function platformSha256(): string
    {
        return $this->platformSha256;
    }

    public function executionPolicySha256(): string
    {
        return $this->executionPolicySha256;
    }

    /** @return array{manifest_sha256: string, lock_sha256: string, platform_sha256: string, execution_policy_sha256: string, state_sha256: string} */
    public function toArray(): array
    {
        return [
            'manifest_sha256' => $this->manifestSha256,
            'lock_sha256' => $this->lockSha256,
            'platform_sha256' => $this->platformSha256,
            'execution_policy_sha256' => $this->executionPolicySha256,
            'state_sha256' => $this->stateSha256,
        ];
    }

    /**
     * Drops the lock fields Composer derives from the manifest as written on disk.
     *
     * Analyzer workspaces rewrite relative path repositories to absolute ones so
     * Composer can resolve them from a temporary directory, so its `content-hash`
     * of that manifest changes with the directory the project was analyzed in. The
     * manifest is fingerprinted separately, which is what the hash restates, so a
     * candidate state stays identifiable without it. The recorded candidate lock
     * evidence keeps the value Composer actually wrote.
     *
     * @param array<mixed> $lock
     * @return array<mixed>
     */
    private static function withoutDerivedLockFields(array $lock): array
    {
        unset($lock['content-hash']);

        return $lock;
    }

    /**
     * Normalizes the separators that follow an exposure marker.
     *
     * Sanitization replaces a private root with a marker but leaves the remaining
     * segments, and those keep the host separator. The same project analyzed on
     * Windows and on Linux is one state, so the digest reads those remainders in a
     * single spelling.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function portable($value)
    {
        if (is_string($value)) {
            return self::portableText($value);
        }
        if (!is_array($value)) {
            return $value;
        }

        $portable = [];
        foreach ($value as $key => $item) {
            $portable[is_string($key) ? self::portableText($key) : $key] = self::portable($item);
        }

        return $portable;
    }

    private static function portableText(string $value): string
    {
        $markers = implode('|', array_map(
            static fn (string $marker): string => preg_quote(trim($marker, '[]'), '~'),
            [
                PathExposurePolicy::PROJECT_ROOT,
                PathExposurePolicy::REPORT_OUTPUT,
                PathExposurePolicy::LOCAL_REPOSITORY,
                PathExposurePolicy::ANALYZER_WORKSPACE,
            ]
        ));

        return preg_replace_callback(
            '~\[(?:' . $markers . ')\](?:[\\\\/][^\s"\'(),:;]*)*~',
            static fn (array $matches): string => str_replace('\\', '/', $matches[0]),
            $value
        ) ?? $value;
    }

    /** @param mixed $value */
    private static function digest($value): string
    {
        $encoded = json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
    }

    /** @return array<string, string|false> */
    private static function semanticPlatformDecisions(TargetPlatform $platform): array
    {
        $decisions = [];
        foreach ($platform->platformPackages() as $package) {
            if ($package->name() === 'php') {
                continue;
            }
            if ($platform->isCompleteProfile() && $package->isAbsent() && !$package->isToolchainBound()) {
                continue;
            }

            $decisions[$package->name()] = $package->composerValue();
        }
        ksort($decisions, SORT_STRING);

        return $decisions;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!self::isList($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            ++$expected;
        }

        return true;
    }
}
