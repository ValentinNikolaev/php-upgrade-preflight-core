<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Framework;

final class FrameworkDetection
{
    public string $framework;
    public bool $detected;
    public ?string $version;

    public function __construct(string $framework, bool $detected, ?string $version = null)
    {
        $this->framework = $framework;
        $this->detected = $detected;
        $this->version = $version;
    }
}
