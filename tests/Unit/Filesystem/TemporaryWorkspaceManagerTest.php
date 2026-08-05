<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Filesystem;

use PhpUpgradePreflight\Core\Filesystem\TemporaryWorkspaceManager;
use PhpUpgradePreflight\Tests\Support\FixtureSnapshot;
use PHPUnit\Framework\TestCase;

final class TemporaryWorkspaceManagerTest extends TestCase
{
    public function testItLeavesEveryOriginalFixtureFileUnchangedWhenTheWorkspaceIsModified(): void
    {
        $fixturePath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'laravel-app';
        $snapshot = FixtureSnapshot::capture($fixturePath);
        $workspaces = new TemporaryWorkspaceManager();
        $workspacePath = $workspaces->createFromProject($fixturePath);

        try {
            file_put_contents($workspacePath . DIRECTORY_SEPARATOR . 'composer.json', "{}\n");
            file_put_contents($workspacePath . DIRECTORY_SEPARATOR . 'composer.lock', "{}\n");

            $snapshot->assertUnchanged($this);
        } finally {
            $workspaces->remove($workspacePath);
        }
    }
}
