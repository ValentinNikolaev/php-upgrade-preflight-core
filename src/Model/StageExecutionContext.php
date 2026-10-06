<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class StageExecutionContext
{
    private string $analysisPhp;
    private string $platformCompleteness;
    private ?string $profileSha256;
    private string $platformSha256;
    /** @var array<string, mixed> */
    private array $executionPolicy;
    private string $executionPolicySha256;

    public function __construct(
        string $analysisPhp,
        TargetPlatform $platform,
        ComposerExecutionConfiguration $execution,
        ProjectStateFingerprint $fingerprint
    ) {
        $normalizedPhp = (new UpgradeTargetSet([], $analysisPhp))->targetPhp();
        if ($normalizedPhp === null) {
            throw new \InvalidArgumentException('A stage execution context requires an exact PHP value.');
        }

        $this->analysisPhp = $normalizedPhp;
        $this->platformCompleteness = $platform->isCompleteProfile() ? 'complete' : 'partial';
        $this->profileSha256 = $platform->profileDigest();
        $this->platformSha256 = $fingerprint->platformSha256();
        $this->executionPolicy = $execution->fingerprintData();
        $this->executionPolicySha256 = $fingerprint->executionPolicySha256();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'platform' => [
                'analysis_php' => $this->analysisPhp,
                'completeness' => $this->platformCompleteness,
                'profile_sha256' => $this->profileSha256,
                'effective_sha256' => $this->platformSha256,
            ],
            'composer_execution' => [
                'configuration' => $this->executionPolicy,
                'effective_sha256' => $this->executionPolicySha256,
            ],
        ];
    }
}
