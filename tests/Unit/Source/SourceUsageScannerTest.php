<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Source;

use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Source\SourceUsageScanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SourceUsageScannerTest extends TestCase
{
    public function testMissingExplicitPathProducesAnUncertainty(): void
    {
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        $usages = (new SourceUsageScanner())->scan($project, ['does-not-exist'], $evidence, $uncertainties, true);

        self::assertSame([], $usages);
        self::assertSame([], $evidence->all());
        self::assertContains('Source path "does-not-exist" does not exist and was not scanned.', $uncertainties);
        self::assertContains('No PHP source files were scanned.', $uncertainties);
    }

    public function testPathOutsideTheProjectIsRejected(): void
    {
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        (new SourceUsageScanner())->scan($project, ['..'], $evidence, $uncertainties, true);

        self::assertStringContainsString('outside the analyzed project', $uncertainties[0]);
    }

    public function testValidSourcePathIsScannedWithoutUncertainty(): void
    {
        $projectPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'project-isolation';
        $project = (new ProjectStateBuilder())->build($projectPath);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);

        self::assertSame([], $usages);
        self::assertSame([], $evidence->all());
        self::assertSame([], $uncertainties);
    }

    public function testAstExtractionIgnoresCommentsStringsAndClosureUses(): void
    {
        $projectPath = $this->createProject(<<<'PHP'
<?php

namespace App;

use Vendor\Package\Client;
use function Vendor\Package\helper;
use Vendor\Package\{BaseClass, Contract};

// new Fake\CommentOnly();
final class Example extends BaseClass implements Contract
{
    use LocalTrait;

    public function run($value): Client
    {
        $closure = function () use ($value): string {
            return 'Phantom\\StringOnly::call()';
        };

        Client::send();
        helper();

        return new Client();
    }
}
PHP);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);
            $symbols = array_map(static fn ($usage): string => $usage->symbol, $usages);
            $usagePairs = array_map(static fn ($usage): array => [$usage->symbol, $usage->usageType], $usages);

            self::assertContains('Vendor\\Package\\Client', $symbols);
            self::assertContains('Vendor\\Package\\BaseClass', $symbols);
            self::assertContains('Vendor\\Package\\Contract', $symbols);
            self::assertContains('Vendor\\Package\\helper', $symbols);
            self::assertContains('App\\LocalTrait', $symbols);
            self::assertNotContains('Fake\\CommentOnly', $symbols);
            self::assertNotContains('Phantom\\StringOnly', $symbols);
            self::assertNotContains('($value)', $symbols);
            self::assertContains(['Vendor\\Package\\Client', 'namespace_import'], $usagePairs);
            self::assertContains(['Vendor\\Package\\BaseClass', 'namespace_import'], $usagePairs);
            self::assertContains(['Vendor\\Package\\Contract', 'namespace_import'], $usagePairs);
            self::assertContains(['App\\LocalTrait', 'trait_reference'], $usagePairs);
            self::assertContains(['Vendor\\Package\\Client', 'static_call'], $usagePairs);
            self::assertContains(['Vendor\\Package\\Client', 'class_reference'], $usagePairs);
            self::assertContains(['Vendor\\Package\\helper', 'function_import'], $usagePairs);
            self::assertContains(['Vendor\\Package\\helper', 'function_call'], $usagePairs);
            self::assertSame([], $uncertainties);
            self::assertNotEmpty($evidence->all());
            self::assertContainsOnly('int', array_map(static fn ($usage): ?int => $usage->line, $usages));
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testSyntaxFailureProducesEvidenceAndUncertainty(): void
    {
        $projectPath = $this->createProject("<?php\nnew ;\n");
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);

            self::assertSame([], $usages);
            self::assertCount(1, $evidence->all());
            self::assertSame('source-1', $evidence->all()[0]->id);
            self::assertStringContainsString('could not be parsed', $uncertainties[0]);
            self::assertStringContainsString('source-1', $uncertainties[0]);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testNestedFileSymlinkOutsideTheProjectIsRejected(): void
    {
        $projectPath = $this->createProject("<?php\n");
        $outsidePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'source-usage-outside-' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($outsidePath, "<?php\nOutside\\Secret::read();\n");
        $linkPath = $projectPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'outside.php';

        try {
            if (!@symlink($outsidePath, $linkPath)) {
                self::markTestSkipped('File symlinks are not available in this environment.');
            }

            $project = (new ProjectStateBuilder())->build($projectPath);
            $evidence = new EvidenceLedger();
            $uncertainties = [];
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);

            self::assertSame([], $usages);
            self::assertSame([], $evidence->all());
            self::assertStringContainsString('resolves outside the analyzed project', $uncertainties[0]);
        } finally {
            (new Filesystem())->remove([$projectPath, $outsidePath]);
        }
    }

    public function testNestedDirectorySymlinkOutsideTheProjectIsRejected(): void
    {
        $projectPath = $this->createProject("<?php\n");
        $outsidePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'source-usage-outside-' . bin2hex(random_bytes(8));
        mkdir($outsidePath, 0700, true);
        file_put_contents($outsidePath . DIRECTORY_SEPARATOR . 'Secret.php', "<?php\nOutside\\Secret::read();\n");
        $linkPath = $projectPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'outside-directory';

        try {
            if (!@symlink($outsidePath, $linkPath)) {
                self::markTestSkipped('Directory symlinks are not available in this environment.');
            }

            $project = (new ProjectStateBuilder())->build($projectPath);
            $evidence = new EvidenceLedger();
            $uncertainties = [];
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);

            self::assertSame([], $usages);
            self::assertSame([], $evidence->all());
            self::assertStringContainsString('resolves outside the analyzed project', $uncertainties[0]);
            self::assertStringContainsString('outside-directory', $uncertainties[0]);
        } finally {
            (new Filesystem())->remove([$projectPath, $outsidePath]);
        }
    }

    private function createProject(string $source): string
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'source-usage-project-' . bin2hex(random_bytes(8));
        mkdir($projectPath . DIRECTORY_SEPARATOR . 'src', 0700, true);
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.json', "{\"require\":{}}\n");
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.lock', "{\"packages\":[],\"packages-dev\":[]}\n");
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Example.php', $source);

        return $projectPath;
    }
}
