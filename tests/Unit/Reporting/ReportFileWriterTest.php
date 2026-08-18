<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Reporting;

use PhpUpgradePreflight\Core\Reporting\ReportDestinationFilesystem;
use PhpUpgradePreflight\Core\Reporting\ReportFileWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;

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

    public function testContainmentRejectsANestedDestinationWithoutWritingAnything(): void
    {
        $filesystem = new InMemoryReportDestinationFilesystem();
        $filesystem->directories = ['/project', '/project/storage'];

        try {
            (new ReportFileWriter($filesystem))->write('/project', '/project/storage/report.json', "{}\n");
            self::fail('Expected a destination inside the analyzed project to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Report output must be outside the analyzed project to preserve its read-only input contract.',
                $exception->getMessage()
            );
        }

        self::assertSame([], $filesystem->written);
    }

    public function testContainmentRejectsADestinationEqualToTheAnalyzedProject(): void
    {
        $filesystem = new InMemoryReportDestinationFilesystem();
        $filesystem->directories = ['/project'];

        try {
            (new ReportFileWriter($filesystem))->write('/project', '/project', "{}\n");
            self::fail('Expected the project directory itself to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('outside the analyzed project', $exception->getMessage());
        }

        self::assertSame([], $filesystem->written);
    }

    public function testContainmentRejectsADestinationThatResolvesIntoTheProjectThroughALinkedParent(): void
    {
        $filesystem = new InMemoryReportDestinationFilesystem();
        $filesystem->directories = ['/project', '/project/reports', '/elsewhere'];
        $filesystem->resolutions = ['/elsewhere/link' => '/project/reports'];

        try {
            (new ReportFileWriter($filesystem))->write('/project', '/elsewhere/link/report.json', "{}\n");
            self::fail('Expected a destination resolving into the analyzed project to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('outside the analyzed project', $exception->getMessage());
        }

        self::assertSame([], $filesystem->written);
    }

    public function testContainmentAllowsADestinationOutsideTheAnalyzedProject(): void
    {
        $filesystem = new InMemoryReportDestinationFilesystem();
        $filesystem->directories = ['/project', '/elsewhere'];

        $writtenPath = (new ReportFileWriter($filesystem))->write('/project', '/elsewhere/report.json', "{}\n");

        self::assertSame('/elsewhere/report.json', $writtenPath);
        self::assertSame(['/elsewhere/report.json' => "{}\n"], $filesystem->written);
    }

    public function testContainmentAllowsASiblingDirectorySharingTheProjectPathPrefix(): void
    {
        $filesystem = new InMemoryReportDestinationFilesystem();
        $filesystem->directories = ['/project', '/project-reports'];

        $writtenPath = (new ReportFileWriter($filesystem))->write('/project', '/project-reports/report.json', "{}\n");

        self::assertSame('/project-reports/report.json', $writtenPath);
        self::assertSame(['/project-reports/report.json' => "{}\n"], $filesystem->written);
    }

    public function testContainmentRequiresAResolvableAnalyzedProjectPath(): void
    {
        $filesystem = new InMemoryReportDestinationFilesystem();
        $filesystem->directories = ['/elsewhere'];

        try {
            (new ReportFileWriter($filesystem))->write('/missing-project', '/elsewhere/report.json', "{}\n");
            self::fail('Expected an unresolvable project path to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('The analyzed project path does not exist.', $exception->getMessage());
        }

        self::assertSame([], $filesystem->written);
    }

    public function testAnExistingUnwritableDestinationIsRejectedBeforeAnyWrite(): void
    {
        $filesystem = new InMemoryReportDestinationFilesystem();
        $filesystem->directories = ['/project', '/elsewhere'];
        $filesystem->files = ['/elsewhere/report.json'];
        $filesystem->unwritablePaths = ['/elsewhere/report.json'];

        try {
            (new ReportFileWriter($filesystem))->write('/project', '/elsewhere/report.json', "{}\n");
            self::fail('Expected an unwritable destination to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('The report output path is not writable.', $exception->getMessage());
        }

        self::assertSame([], $filesystem->written);
    }

    public function testADestinationThatExistsAsANonRegularFileSkipsTheParentWritabilityCheck(): void
    {
        $filesystem = new InMemoryReportDestinationFilesystem();
        $filesystem->directories = ['/project', '/elsewhere'];
        $filesystem->specialFiles = ['/elsewhere/sink'];
        $filesystem->unwritablePaths = ['/elsewhere'];

        $writtenPath = (new ReportFileWriter($filesystem))->write('/project', '/elsewhere/sink', "{}\n");

        self::assertSame('/elsewhere/sink', $writtenPath);
        self::assertSame(['/elsewhere/sink' => "{}\n"], $filesystem->written);
    }
}

/**
 * An in-memory report destination used to exercise the containment rule without a real tree.
 */
final class InMemoryReportDestinationFilesystem implements ReportDestinationFilesystem
{
    /** @var list<string> */
    public array $directories = [];

    /** @var list<string> */
    public array $files = [];

    /**
     * Paths that exist but are neither a regular file nor a directory, such as a device node.
     *
     * @var list<string>
     */
    public array $specialFiles = [];

    /** @var list<string> */
    public array $unwritablePaths = [];

    /**
     * Literal path to the canonical path it resolves to, modelling links.
     *
     * @var array<string, string>
     */
    public array $resolutions = [];

    /** @var array<string, string> */
    public array $written = [];

    public function isDirectory(string $path): bool
    {
        return in_array(Path::canonicalize($path), $this->directories, true);
    }

    public function isFile(string $path): bool
    {
        return in_array(Path::canonicalize($path), $this->files, true);
    }

    public function isWritable(string $path): bool
    {
        return !in_array(Path::canonicalize($path), $this->unwritablePaths, true);
    }

    public function exists(string $path): bool
    {
        return $this->isDirectory($path)
            || $this->isFile($path)
            || in_array(Path::canonicalize($path), $this->specialFiles, true);
    }

    public function resolve(string $path): string|false
    {
        $canonical = Path::canonicalize($path);

        if (isset($this->resolutions[$canonical])) {
            return $this->resolutions[$canonical];
        }

        return $this->exists($canonical) ? $canonical : false;
    }

    public function dumpFile(string $path, string $contents): void
    {
        $this->written[Path::canonicalize($path)] = $contents;
    }
}
