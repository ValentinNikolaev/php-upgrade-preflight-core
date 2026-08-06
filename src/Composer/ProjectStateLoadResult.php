<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Model\ProjectState;

final class ProjectStateLoadResult
{
    private ProjectState $project;
    private ?JsonFileException $failure;

    public function __construct(ProjectState $project, ?JsonFileException $failure = null)
    {
        $this->project = $project;
        $this->failure = $failure;
    }

    public function project(): ProjectState
    {
        return $this->project;
    }

    public function failure(): ?JsonFileException
    {
        return $this->failure;
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }
}
