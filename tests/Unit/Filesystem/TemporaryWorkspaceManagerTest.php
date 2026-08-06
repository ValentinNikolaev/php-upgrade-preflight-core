<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Filesystem;

use PhpUpgradePreflight\Core\Filesystem\NativeWorkspaceFilesystem;
use PhpUpgradePreflight\Core\Filesystem\TemporaryWorkspaceManager;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceFilesystem;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceCleanupException;
use PhpUpgradePreflight\Tests\Support\FixtureSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TemporaryWorkspaceManagerTest extends TestCase
{
    public function testItLeavesEveryOriginalFixtureFileUnchangedWhenTheWorkspaceIsModified(): void
    {
        $fixturePath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
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

    public function testItUnlinksDirectorySymlinksWithoutRemovingTheirTargets(): void
    {
        $fixturePath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $externalPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-link-target-' . bin2hex(random_bytes(8));
        $workspaces = new TemporaryWorkspaceManager();
        $workspacePath = $workspaces->createFromProject($fixturePath);
        mkdir($externalPath, 0700, true);
        file_put_contents($externalPath . DIRECTORY_SEPARATOR . 'marker.txt', 'preserve');
        mkdir($workspacePath . DIRECTORY_SEPARATOR . 'vendor', 0700, true);
        $linkPath = $workspacePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'dependency';

        try {
            if (!@symlink($externalPath, $linkPath)) {
                self::markTestSkipped('Directory symlinks are not available in this environment.');
            }

            $workspaces->remove($workspacePath);

            self::assertDirectoryDoesNotExist($workspacePath);
            self::assertFileExists($externalPath . DIRECTORY_SEPARATOR . 'marker.txt');
        } finally {
            (new Filesystem())->remove([$workspacePath, $externalPath]);
        }
    }

    public function testFailedInitializationRemovesThePartialWorkspace(): void
    {
        $projectPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'workspace-source-' . bin2hex(random_bytes(8));
        mkdir($projectPath, 0700, true);
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.json', "{}\n");
        $before = glob(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-*') ?: [];
        sort($before);

        try {
            try {
                (new TemporaryWorkspaceManager())->createFromProject($projectPath);
                self::fail('Expected workspace initialization to fail without composer.lock.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('composer.lock', $exception->getMessage());
            }

            $after = glob(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-*') ?: [];
            sort($after);
            self::assertSame($before, $after);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testDirectoryCreationFailureIsReported(): void
    {
        $filesystem = new ControllableWorkspaceFilesystem();
        $filesystem->failDirectoryCreation = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to create temporary workspace');

        (new TemporaryWorkspaceManager($filesystem))->createFromProject(dirname(__DIR__, 5));
    }

    public function testCopyFailureIsReportedAndRemovesThePartialWorkspace(): void
    {
        $filesystem = new ControllableWorkspaceFilesystem();
        $filesystem->copyFailureBasename = 'composer.lock';
        $manager = new TemporaryWorkspaceManager($filesystem);
        $fixturePath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';

        try {
            $manager->createFromProject($fixturePath);
            self::fail('Expected the composer.lock copy to fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Unable to copy', $exception->getMessage());
            self::assertStringContainsString('composer.lock', $exception->getMessage());
        }

        self::assertNotNull($filesystem->createdDirectory);
        self::assertDirectoryDoesNotExist($filesystem->createdDirectory);
        self::assertSame(['composer.json', 'composer.lock'], $filesystem->copiedBasenames);
    }

    public function testCleanupFailureIsReportedAndCanBeRetried(): void
    {
        $filesystem = new ControllableWorkspaceFilesystem();
        $manager = new TemporaryWorkspaceManager($filesystem);
        $fixturePath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $workspacePath = $manager->createFromProject($fixturePath);
        $filesystem->failDirectoryRemoval = true;

        try {
            try {
                $manager->remove($workspacePath);
                self::fail('Expected workspace cleanup to fail.');
            } catch (WorkspaceCleanupException $exception) {
                self::assertStringContainsString('Unable to remove temporary workspace directory', $exception->getMessage());
                self::assertSame($workspacePath, $exception->workspacePath());
                self::assertDirectoryExists($workspacePath);
            }
        } finally {
            $filesystem->failDirectoryRemoval = false;
            $manager->remove($workspacePath);
        }

        self::assertDirectoryDoesNotExist($workspacePath);
    }

    public function testInitializationCleanupFailureCarriesTheLeakedWorkspacePath(): void
    {
        $filesystem = new ControllableWorkspaceFilesystem();
        $filesystem->copyFailureBasename = 'composer.lock';
        $filesystem->failDirectoryRemoval = true;
        $manager = new TemporaryWorkspaceManager($filesystem);
        $fixturePath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $workspacePath = null;

        try {
            try {
                $manager->createFromProject($fixturePath);
                self::fail('Expected initialization cleanup to fail.');
            } catch (WorkspaceCleanupException $exception) {
                $workspacePath = $exception->workspacePath();
                self::assertStringContainsString('Cleanup of partial workspace', $exception->getMessage());
                self::assertSame($filesystem->createdDirectory, $workspacePath);
                self::assertDirectoryExists($workspacePath);
            }
        } finally {
            $filesystem->failDirectoryRemoval = false;
            if ($workspacePath !== null) {
                $manager->remove($workspacePath);
            }
        }

        self::assertDirectoryDoesNotExist($workspacePath);
    }
}

final class ControllableWorkspaceFilesystem implements WorkspaceFilesystem
{
    public bool $failDirectoryCreation = false;
    public bool $failDirectoryRemoval = false;
    public ?string $copyFailureBasename = null;
    public ?string $createdDirectory = null;
    /** @var list<string> */
    public array $copiedBasenames = [];
    private NativeWorkspaceFilesystem $native;

    public function __construct()
    {
        $this->native = new NativeWorkspaceFilesystem();
    }

    public function createDirectory(string $path, int $mode, bool $recursive): bool
    {
        $this->createdDirectory = $path;

        return !$this->failDirectoryCreation && $this->native->createDirectory($path, $mode, $recursive);
    }

    public function copy(string $source, string $destination): bool
    {
        $this->copiedBasenames[] = basename($source);
        if (basename($source) === $this->copyFailureBasename) {
            return false;
        }

        return $this->native->copy($source, $destination);
    }

    public function unlink(string $path): bool
    {
        return $this->native->unlink($path);
    }

    public function removeDirectory(string $path): bool
    {
        return !$this->failDirectoryRemoval && $this->native->removeDirectory($path);
    }
}
