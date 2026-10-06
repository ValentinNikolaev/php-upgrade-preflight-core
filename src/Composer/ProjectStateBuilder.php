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
        $composerJsonPath = $projectPath . DIRECTORY_SEPARATOR . 'composer.json';

        try {
            $composerJson = $this->reader->read($composerJsonPath);
        } catch (JsonFileException $exception) {
            return new ProjectStateLoadResult(
                new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([])),
                $exception
            );
        }

        // The manifest model rejects a contradictory manifest at construction, so it is
        // built once, here, where the rejection can still become a structured result.
        try {
            $manifest = new ComposerJson($composerJson);
        } catch (\InvalidArgumentException $exception) {
            return new ProjectStateLoadResult(
                new ProjectState($projectPath, new ComposerJson([]), new ComposerLock([])),
                new InvalidJsonException(
                    $composerJsonPath,
                    sprintf('Invalid Composer file "composer.json": %s', $exception->getMessage())
                )
            );
        }

        try {
            $composerLock = $this->reader->read($projectPath . DIRECTORY_SEPARATOR . 'composer.lock');
        } catch (JsonFileException $exception) {
            return new ProjectStateLoadResult(
                new ProjectState($projectPath, $manifest, new ComposerLock([])),
                $exception
            );
        }

        return new ProjectStateLoadResult(
            new ProjectState(
                $projectPath,
                $manifest,
                new ComposerLock($composerLock, array_keys($manifest->rootRequirements()))
            )
        );
    }
}
