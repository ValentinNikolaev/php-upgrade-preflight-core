<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ProjectState
{
    public string $path;
    public ComposerJson $composerJson;
    public ComposerLock $composerLock;

    public function __construct(string $path, ComposerJson $composerJson, ComposerLock $composerLock)
    {
        $this->path = $path;
        $this->composerJson = $composerJson;
        $this->composerLock = $composerLock;
    }

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'platform_php' => $this->composerJson->platformPhp(),
            'root_requirements' => $this->composerJson->rootRequirements(),
            'locked_packages' => count($this->composerLock->packages()),
        ];
    }
}
