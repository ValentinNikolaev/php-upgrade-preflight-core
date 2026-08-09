<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Filesystem;

use PhpUpgradePreflight\Core\Support\PathExposurePolicy;

final class WorkspaceCleanupException extends \RuntimeException
{
    private string $workspacePath;

    public function __construct(string $workspacePath, string $message, ?\Throwable $previous = null)
    {
        // Native exception chains can reintroduce raw paths when PHP renders the throwable.
        $safeMessage = PathExposurePolicy::redactComposerText($message, null, $workspacePath);
        if ($previous !== null) {
            $safeCause = PathExposurePolicy::redactComposerText(
                $previous->getMessage(),
                null,
                $workspacePath
            );
            if ($safeCause !== '' && !str_contains($safeMessage, $safeCause)) {
                $safeMessage .= ' Cause: ' . $safeCause;
            }
        }

        parent::__construct($safeMessage, 0, null);
        $this->workspacePath = $workspacePath;
    }

    public function workspacePath(): string
    {
        return $this->workspacePath;
    }

    public function __toString(): string
    {
        return sprintf('%s: %s', self::class, $this->getMessage());
    }
}
