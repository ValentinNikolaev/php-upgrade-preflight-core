<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Reporting;

use PhpUpgradePreflight\Core\Reporting\ReportFileWriter;
use PHPUnit\Framework\TestCase;

final class ReportFileWriterTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'report-file-writer-' . bin2hex(random_bytes(8));
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

    public function testItWritesAReportAtomicallyOutsideTheProject(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $outputPath = $this->outputDirectory . DIRECTORY_SEPARATOR . 'report.json';

        $writtenPath = (new ReportFileWriter())->write($projectPath, $outputPath, "{}\n");

        self::assertSame("{}\n", file_get_contents($outputPath));
        self::assertSame(str_replace('\\', '/', $outputPath), str_replace('\\', '/', $writtenPath));
    }

    public function testItRejectsAnOutputInsideTheAnalyzedProject(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $composerPath = $projectPath . DIRECTORY_SEPARATOR . 'composer.json';
        $before = file_get_contents($composerPath);

        try {
            (new ReportFileWriter())->write($projectPath, $composerPath, "overwritten\n");
            self::fail('Expected the project output path to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('outside the analyzed project', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($composerPath));
    }

    public function testItRejectsAMissingOutputDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        (new ReportFileWriter())->write(dirname(__DIR__, 5), $this->outputDirectory . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'report.json', "{}\n");
    }

    public function testItCanValidateADestinationWithoutCreatingTheReport(): void
    {
        $outputPath = $this->outputDirectory . DIRECTORY_SEPARATOR . 'report.json';

        $validated = (new ReportFileWriter())->validateDestination(dirname(__DIR__, 5), $outputPath);

        self::assertFileDoesNotExist($outputPath);
        self::assertSame(str_replace('\\', '/', $outputPath), str_replace('\\', '/', $validated));
    }
}
