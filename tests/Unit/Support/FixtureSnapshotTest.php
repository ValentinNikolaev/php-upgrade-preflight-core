<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Support;

use PhpUpgradePreflight\Tests\Support\FixtureSnapshot;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class FixtureSnapshotTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fixture-snapshot-' . bin2hex(random_bytes(8));
        mkdir($this->fixturePath . DIRECTORY_SEPARATOR . 'nested', 0700, true);
        file_put_contents($this->fixturePath . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'source.php', "<?php\n\necho 'unchanged';\n");
        file_put_contents($this->fixturePath . DIRECTORY_SEPARATOR . 'binary.bin', "\x00\xff\x10");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function testItAcceptsAnUnchangedFixture(): void
    {
        $snapshot = FixtureSnapshot::capture($this->fixturePath);

        $snapshot->assertUnchanged($this);
    }

    public function testItDetectsByteLevelChangesToAnOriginalFile(): void
    {
        $snapshot = FixtureSnapshot::capture($this->fixturePath);
        file_put_contents($this->fixturePath . DIRECTORY_SEPARATOR . 'binary.bin', "\x00\xff\x11");

        $this->expectException(AssertionFailedError::class);

        $snapshot->assertUnchanged($this);
    }

    public function testItDetectsFilesAddedOrRemovedAfterTheSnapshot(): void
    {
        $snapshot = FixtureSnapshot::capture($this->fixturePath);
        unlink($this->fixturePath . DIRECTORY_SEPARATOR . 'binary.bin');
        file_put_contents($this->fixturePath . DIRECTORY_SEPARATOR . 'created.php', "<?php\n");

        $this->expectException(AssertionFailedError::class);

        $snapshot->assertUnchanged($this);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
