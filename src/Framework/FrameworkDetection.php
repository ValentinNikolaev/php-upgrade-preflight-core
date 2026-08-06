<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Framework;

final class FrameworkDetection
{
    private string $framework;
    private bool $detected;
    private ?string $version;

    public function __construct(string $framework, bool $detected, ?string $version = null)
    {
        $this->framework = $framework;
        $this->detected = $detected;
        $this->version = $version;
    }

    public function framework(): string
    {
        return $this->framework;
    }

    public function isDetected(): bool
    {
        return $this->detected;
    }

    public function version(): ?string
    {
        return $this->version;
    }
}
