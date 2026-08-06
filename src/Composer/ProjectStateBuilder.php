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
        $result = $this->load($projectPath);
        if (!$result->succeeded()) {
            $failure = $result->failure();
            if ($failure === null) {
                throw new \LogicException('A failed project-state load must contain its failure.');
            }

            throw $failure;
        }

        return $result->project();
    }

    public function load(string $projectPath): ProjectStateLoadResult
    {
        try {
            $composerJson = $this->reader->read($projectPath . DIRECTORY_SEPARATOR . 'composer.json');
        } catch (JsonFileException $exception) {
            return new ProjectStateLoadResult(
                new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([])),
                $exception
            );
        }

        try {
            $composerLock = $this->reader->read($projectPath . DIRECTORY_SEPARATOR . 'composer.lock');
        } catch (JsonFileException $exception) {
            return new ProjectStateLoadResult(
                new ProjectState($projectPath, new ComposerJson($composerJson), new ComposerLock([])),
                $exception
            );
        }

        return new ProjectStateLoadResult(
            new ProjectState($projectPath, new ComposerJson($composerJson), new ComposerLock($composerLock))
        );
    }
}
