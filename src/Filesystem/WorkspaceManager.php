<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Filesystem;

interface WorkspaceManager
{
    public function createFromProject(string $projectPath): string;

    public function remove(string $path): void;
}
