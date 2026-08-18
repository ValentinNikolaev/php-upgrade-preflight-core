<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Reporting;

use PhpUpgradePreflight\Core\Reporting\SymfonyReportDestinationFilesystem;
use PHPUnit\Framework\TestCase;

final class SymfonyReportDestinationFilesystemTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'report-destination-' . bin2hex(random_bytes(8));
        mkdir($this->outputDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->outputDirectory);

        parent::tearDown();
    }

    public function testItMirrorsTheNativeProbesItAdapts(): void
    {
        $filePath = $this->outputDirectory . DIRECTORY_SEPARATOR . 'report.json';
        $missingPath = $this->outputDirectory . DIRECTORY_SEPARATOR . 'missing.json';
        $adapter = new SymfonyReportDestinationFilesystem();

        $adapter->dumpFile($filePath, "{}\n");

        self::assertSame("{}\n", file_get_contents($filePath));
        self::assertSame(is_dir($this->outputDirectory), $adapter->isDirectory($this->outputDirectory));
        self::assertSame(is_dir($filePath), $adapter->isDirectory($filePath));
        self::assertSame(is_file($filePath), $adapter->isFile($filePath));
        self::assertSame(is_file($this->outputDirectory), $adapter->isFile($this->outputDirectory));
        self::assertSame(is_writable($filePath), $adapter->isWritable($filePath));
        self::assertSame(file_exists($filePath), $adapter->exists($filePath));
        self::assertSame(file_exists($missingPath), $adapter->exists($missingPath));
        self::assertSame(realpath($filePath), $adapter->resolve($filePath));
        self::assertSame(realpath($missingPath), $adapter->resolve($missingPath));
    }

    public function testItReportsAbsentPathsAsUnresolvableWithoutCreatingThem(): void
    {
        $missingPath = $this->outputDirectory . DIRECTORY_SEPARATOR . 'missing.json';
        $adapter = new SymfonyReportDestinationFilesystem();

        self::assertFalse($adapter->resolve($missingPath));
        self::assertFalse($adapter->exists($missingPath));
        self::assertFalse($adapter->isFile($missingPath));
        self::assertFalse($adapter->isDirectory($missingPath));
        self::assertFileDoesNotExist($missingPath);
    }
}
