<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\ProjectState;

final class ProjectStateBuilder
{
    private JsonFileReader $reader;

    public function __construct(?JsonFileReader $reader = null)
    {
        $this->reader = $reader ?? new JsonFileReader();
    }

    public function build(string $projectPath): ProjectState
    {
        $composerJson = $this->reader->read($projectPath . DIRECTORY_SEPARATOR . 'composer.json');
        $composerLock = $this->reader->read($projectPath . DIRECTORY_SEPARATOR . 'composer.lock');

        return new ProjectState($projectPath, new ComposerJson($composerJson), new ComposerLock($composerLock));
    }
}
