<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ProjectState
{
    private string $path;
    private ComposerJson $composerJson;
    private ComposerLock $composerLock;

    public function __construct(string $path, ComposerJson $composerJson, ComposerLock $composerLock)
    {
        $this->path = $path;
        $this->composerJson = $composerJson;
        $this->composerLock = $composerLock;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function composerJson(): ComposerJson
    {
        return $this->composerJson;
    }

    public function composerLock(): ComposerLock
    {
        return $this->composerLock;
    }

    /** @return array{path: string, platform_php: ?string, root_requirements: array<string, string>, locked_packages: int} */
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
