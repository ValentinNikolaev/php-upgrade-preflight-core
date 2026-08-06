<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Filesystem;

final class WorkspaceCleanupException extends \RuntimeException
{
    private string $workspacePath;

    public function __construct(string $workspacePath, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->workspacePath = $workspacePath;
    }

    public function workspacePath(): string
    {
        return $this->workspacePath;
    }
}
