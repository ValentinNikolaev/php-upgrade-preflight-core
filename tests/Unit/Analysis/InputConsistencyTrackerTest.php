<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\InputConsistencyTracker;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Source\SourceScanLimits;
use PhpUpgradePreflight\Core\Source\SourceUsageScanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InputConsistencyTrackerTest extends TestCase
{
    public function testFingerprintingStopsReadingAfterTheAggregateByteLimit(): void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'input-consistency-' . bin2hex(random_bytes(8));
        $sourcePath = $projectPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Example.php';
        $secondPath = $projectPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Second.php';
        $first = "<?php\nApp\\First::run();\n";
        mkdir(dirname($sourcePath), 0700, true);
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.json', "{\"require\":{}}\n");
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.lock', "{\"packages\":[],\"packages-dev\":[]}\n");
        file_put_contents($sourcePath, $first);
        file_put_contents($secondPath, "<?php\nApp\\Second::run();\n");

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $scanner = new SourceUsageScanner(null, new SourceScanLimits(10, 1024, strlen($first), 10));
            $tracker = new InputConsistencyTracker($projectPath);
            $tracker->captureSource($scanner, $project, ['src']);
            $tracker->checkSource($scanner, $project, ['src'], []);

            self::assertSame([], $tracker->uncertainties());
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testByteLimitedSourceUsesBoundedMetadataAndStillDetectsDrift(): void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'input-consistency-' . bin2hex(random_bytes(8));
        $sourcePath = $projectPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Example.php';
        mkdir(dirname($sourcePath), 0700, true);
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.json', "{\"require\":{}}\n");
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.lock', "{\"packages\":[],\"packages-dev\":[]}\n");
        file_put_contents($sourcePath, "<?php\n" . str_repeat('x', 32));

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $scanner = new SourceUsageScanner(null, new SourceScanLimits(10, 8, 64, 10));
            $tracker = new InputConsistencyTracker($projectPath);
            $tracker->captureSource($scanner, $project, ['src']);
            $tracker->checkSource($scanner, $project, ['src'], []);

            self::assertSame([], $tracker->uncertainties());

            file_put_contents($sourcePath, "<?php\n" . str_repeat('y', 48));
            clearstatcache(true, $sourcePath);
            $tracker->checkSource($scanner, $project, ['src'], []);

            self::assertStringContainsString('src/Example.php', $tracker->uncertainties()[0]);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }
}
