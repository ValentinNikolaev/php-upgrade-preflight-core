<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Source;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Framework\FrameworkDetection;
use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Framework\SourceUsageVisitorProvider;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Source\SourceUsageCollector;
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

    public function testMultiHopProjectAliasIsResolvedBeforeSourceContainmentIsChecked(): void
    {
        $projectPath = $this->createProject("<?php\nVendor\\Package\\Client::send();\n");
        $firstAliasPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'source-usage-alias-first-' . bin2hex(random_bytes(8));
        $secondAliasPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'source-usage-alias-second-' . bin2hex(random_bytes(8));

        try {
            if (!@symlink($projectPath, $firstAliasPath) || !@symlink($firstAliasPath, $secondAliasPath)) {
                self::markTestSkipped('Directory symlinks are not available in this environment.');
            }

            $project = (new ProjectStateBuilder())->build($secondAliasPath);
            $evidence = new EvidenceLedger();
            $uncertainties = [];
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);

            self::assertCount(1, $usages);
            self::assertSame('src/Example.php', $usages[0]->file());
            self::assertSame('Vendor\\Package\\Client', $usages[0]->symbol());
            self::assertSame([], $uncertainties);
        } finally {
            (new Filesystem())->remove([$secondAliasPath, $firstAliasPath, $projectPath]);
        }
    }

    public function testBackslashSeparatedSourcePathIsJoinedPortably(): void
    {
        $projectPath = $this->createProject("<?php\n");
        $nestedPath = $projectPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'nested';
        mkdir($nestedPath, 0700, true);
        file_put_contents($nestedPath . DIRECTORY_SEPARATOR . 'Portable.php', "<?php\nVendor\\Package\\Portable::run();\n");

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $evidence = new EvidenceLedger();
            $uncertainties = [];
            $usages = (new SourceUsageScanner())->scan($project, ['src\\nested'], $evidence, $uncertainties, true);

            self::assertCount(1, $usages);
            self::assertSame('src/nested/Portable.php', $usages[0]->file());
            self::assertSame('Vendor\\Package\\Portable', $usages[0]->symbol());
            self::assertSame([], $uncertainties);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testAstExtractionClassifiesSupportedUsagesAndIgnoresNonCodeText(): void
    {
        $projectPath = $this->createProject(<<<'PHP'
<?php

namespace App;

use Vendor\Package\Client;
use function Vendor\Package\helper;
use const Vendor\Package\FLAG;
use Vendor\Package\{BaseClass, Contract};

// new Fake\CommentOnly();
#[\Vendor\Package\ExampleAttribute]
final class Example extends BaseClass implements Contract, \Vendor\Package\OtherContract
{
    use LocalTrait, \Vendor\Package\SharedTrait;

    public function run(\Vendor\Package\Input $value): Client
    {
        $closure = function () use ($value): string {
            return 'Phantom\\StringOnly::call()';
        };

        Client::send();
        Client::$connection;
        Client::STATUS;
        helper();
        \Vendor\Package\direct_helper();
        \Vendor\Package\DIRECT_FLAG;
        true;
        false;
        null;

        return new \Vendor\Package\CreatedClient();
    }

    public function sameLine(\Vendor\Package\SameLine $value): void { new \Vendor\Package\SameLine(); }

    public function importedType(Client $client): void {}
}

interface ChildContract extends \Vendor\Package\ParentContract {}

enum Status implements \Vendor\Package\StatusContract { case Ready; }
PHP);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);
            $symbols = array_map(static fn ($usage): string => $usage->symbol(), $usages);
            $usagePairs = array_map(static fn ($usage): array => [$usage->symbol(), $usage->usageType()], $usages);

            self::assertContains('Vendor\\Package\\Client', $symbols);
            self::assertContains('Vendor\\Package\\BaseClass', $symbols);
            self::assertContains('Vendor\\Package\\Contract', $symbols);
            self::assertContains('Vendor\\Package\\helper', $symbols);
            self::assertContains('App\\LocalTrait', $symbols);
            self::assertContains('Vendor\\Package\\Input', $symbols);
            self::assertContains('Vendor\\Package\\CreatedClient', $symbols);
            self::assertNotContains('Fake\\CommentOnly', $symbols);
            self::assertNotContains('Phantom\\StringOnly', $symbols);
            self::assertNotContains('($value)', $symbols);
            self::assertContains(['Vendor\\Package\\Client', 'namespace_import'], $usagePairs);
            self::assertContains(['Vendor\\Package\\BaseClass', 'namespace_import'], $usagePairs);
            self::assertContains(['Vendor\\Package\\Contract', 'namespace_import'], $usagePairs);
            self::assertContains(['Vendor\\Package\\FLAG', 'constant_import'], $usagePairs);
            self::assertContains(['Vendor\\Package\\BaseClass', 'inheritance'], $usagePairs);
            self::assertContains(['Vendor\\Package\\Contract', 'interface_reference'], $usagePairs);
            self::assertContains(['Vendor\\Package\\OtherContract', 'interface_reference'], $usagePairs);
            self::assertContains(['Vendor\\Package\\ParentContract', 'interface_reference'], $usagePairs);
            self::assertContains(['Vendor\\Package\\StatusContract', 'interface_reference'], $usagePairs);
            self::assertContains(['App\\LocalTrait', 'trait_reference'], $usagePairs);
            self::assertContains(['Vendor\\Package\\SharedTrait', 'trait_reference'], $usagePairs);
            self::assertContains(['Vendor\\Package\\ExampleAttribute', 'attribute'], $usagePairs);
            self::assertContains(['Vendor\\Package\\Client', 'static_call'], $usagePairs);
            self::assertContains(['Vendor\\Package\\Client', 'static_property_access'], $usagePairs);
            self::assertContains(['Vendor\\Package\\Client', 'class_constant_access'], $usagePairs);
            self::assertContains(['Vendor\\Package\\CreatedClient', 'instantiated_class'], $usagePairs);
            self::assertContains(['Vendor\\Package\\Input', 'fully_qualified_name'], $usagePairs);
            self::assertContains(['Vendor\\Package\\SameLine', 'instantiated_class'], $usagePairs);
            self::assertContains(['Vendor\\Package\\SameLine', 'fully_qualified_name'], $usagePairs);
            self::assertNotContains(['Vendor\\Package\\Client', 'fully_qualified_name'], $usagePairs);
            self::assertContains(['Vendor\\Package\\helper', 'function_import'], $usagePairs);
            self::assertContains(['Vendor\\Package\\helper', 'function_call'], $usagePairs);
            self::assertContains(['Vendor\\Package\\direct_helper', 'function_call'], $usagePairs);
            self::assertContains(['Vendor\\Package\\DIRECT_FLAG', 'constant_access'], $usagePairs);
            self::assertNotContains(['true', 'constant_access'], $usagePairs);
            self::assertNotContains(['false', 'constant_access'], $usagePairs);
            self::assertNotContains(['null', 'constant_access'], $usagePairs);
            self::assertSame([], $uncertainties);
            self::assertNotEmpty($evidence->all());
            self::assertContainsOnly('int', array_map(static fn ($usage): ?int => $usage->line(), $usages));
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testItPreservesRepeatedUsagesAtEveryExactLineWhileDeduplicatingExactObservations(): void
    {
        $projectPath = $this->createProject(<<<'PHP'
<?php
Vendor\Package\Client::first();
Vendor\Package\Client::second();
Vendor\Package\Client::$connection;
PHP);
        file_put_contents(
            $projectPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Other.php',
            "<?php\nVendor\\Package\\Client::third();\n"
        );
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);

            self::assertCount(4, $usages);
            self::assertSame('src/Example.php', $usages[0]->file());
            self::assertSame('Vendor\Package\Client', $usages[0]->symbol());
            self::assertSame('static_call', $usages[0]->usageType());
            self::assertSame(2, $usages[0]->line());
            self::assertSame(['source-1'], $usages[0]->evidence());
            self::assertSame(3, $usages[1]->line());
            self::assertSame(['source-2'], $usages[1]->evidence());
            self::assertSame('static_property_access', $usages[2]->usageType());
            self::assertSame(['source-3'], $usages[2]->evidence());
            self::assertSame('src/Other.php', $usages[3]->file());
            self::assertSame('static_call', $usages[3]->usageType());
            self::assertSame(['source-4'], $usages[3]->evidence());
            self::assertSame(
                [
                    ['src/Example.php', 2, 'static_call'],
                    ['src/Example.php', 3, 'static_call'],
                    ['src/Example.php', 4, 'static_property_access'],
                    ['src/Other.php', 2, 'static_call'],
                ],
                array_map(
                    static fn (Evidence $item): array => [
                        $item->context()['file'],
                        $item->context()['line'],
                        $item->context()['usage_type'],
                    ],
                    $evidence->all()
                )
            );
            $evidence->validateReferences(array_merge(...array_map(
                static fn ($usage): array => $usage->evidence(),
                $usages
            )));
            self::assertSame([], $uncertainties);
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
            self::assertSame('source-1', $evidence->all()[0]->id());
            self::assertSame(Evidence::E3_PROJECT_SOURCE, $evidence->all()[0]->evidenceClass());
            self::assertSame('high', $evidence->all()[0]->confidence());
            self::assertSame('src/Example.php', $evidence->all()[0]->context()['file']);
            self::assertSame(2, $evidence->all()[0]->context()['line']);
            self::assertSame('nikic/php-parser', $evidence->all()[0]->context()['parser']);
            self::assertSame('parse_error', $evidence->all()[0]->context()['failure_type']);
            self::assertNotSame('', $evidence->all()[0]->context()['error']);
            self::assertStringContainsString('could not be parsed', $uncertainties[0]);
            self::assertStringContainsString('source-1', $uncertainties[0]);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    /**
     * Core owns no framework vocabulary. Without an active integration that contributes
     * a collector, a Laravel-shaped project must yield only framework-neutral usage
     * types; the Laravel vocabulary now belongs to the Laravel adapter.
     *
     * @see \PhpUpgradePreflight\Laravel\Tests\Unit\Source\LaravelSourceUsageVisitorTest
     */
    public function testScanWithoutIntegrationsEmitsNoFrameworkVocabulary(): void
    {
        $projectPath = $this->createProject(<<<'PHP'
<?php

namespace App\Services;

config('services.mailgun.domain');
config()->get('cache.default');
PHP);

        $sources = [
            'config/app.php' => <<<'PHP'
<?php

return [
    'providers' => [
        Vendor\Package\PackageServiceProvider::class,
    ],
    'aliases' => [
        'Package' => Vendor\Package\Facades\Package::class,
    ],
];
PHP,
            'bootstrap/providers.php' => <<<'PHP'
<?php

return [App\Providers\BootstrapServiceProvider::class];
PHP,
            'app/Http/Kernel.php' => <<<'PHP'
<?php

namespace App\Http;

final class Kernel
{
    protected $middleware = [\App\Http\Middleware\TrustHosts::class];
    protected $commands = [\App\Console\Commands\RebuildIndex::class];
}
PHP,
        ];

        foreach ($sources as $path => $source) {
            $fullPath = $projectPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            mkdir(dirname($fullPath), 0700, true);
            file_put_contents($fullPath, $source);
        }

        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan(
                $project,
                ['src', 'app', 'bootstrap', 'config'],
                $evidence,
                $uncertainties,
                true
            );
            $usageTypes = array_values(array_unique(array_map(
                static fn ($usage): string => $usage->usageType(),
                $usages
            )));

            foreach ([
                'config_reference',
                'console_command',
                'deprecated_queue_dispatch',
                'facade_alias',
                'middleware_reference',
                'service_provider',
                'test_double',
            ] as $frameworkUsageType) {
                self::assertNotContains(
                    $frameworkUsageType,
                    $usageTypes,
                    sprintf('Core must not emit the adapter usage type "%s".', $frameworkUsageType)
                );
            }

            self::assertContains('class_constant_access', $usageTypes);
            self::assertSame([], $uncertainties);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testSyntaxFailureDoesNotPreventOtherFilesFromBeingScanned(): void
    {
        $projectPath = $this->createProject("<?php\nnew ;\n");
        file_put_contents(
            $projectPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Valid.php',
            "<?php\nVendor\\Package\\Client::send();\n"
        );
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);

            self::assertCount(1, $usages);
            self::assertSame('Vendor\\Package\\Client', $usages[0]->symbol());
            self::assertSame('static_call', $usages[0]->usageType());
            self::assertCount(2, $evidence->all());
            self::assertSame('parse_error', $evidence->all()[0]->context()['failure_type']);
            self::assertSame('static_call', $evidence->all()[1]->context()['usage_type']);
            self::assertCount(1, $uncertainties);
            self::assertStringContainsString('source-1', $uncertainties[0]);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testDefaultTraversalExcludesDependenciesAndGeneratedDirectoriesButAllowsADirectPath(): void
    {
        $projectPath = $this->createProject("<?php\nApp\\Primary::run();\n");
        $excludedSources = [
            'src' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'package' => 'Vendor\\Dependency',
            'src' . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'package' => 'Node\\Dependency',
            'src' . DIRECTORY_SEPARATOR . 'generated' => 'Generated\\Proxy',
            'src' . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache' => 'Cached\\Bootstrap',
            'src' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' => 'Cached\\Framework',
            'src' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' => 'Cached\\Runtime',
        ];

        foreach ($excludedSources as $directory => $symbol) {
            mkdir($projectPath . DIRECTORY_SEPARATOR . $directory, 0700, true);
            file_put_contents(
                $projectPath . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . 'Excluded.php',
                sprintf("<?php\n%s::run();\n", $symbol)
            );
        }

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $evidence = new EvidenceLedger();
            $uncertainties = [];
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);

            self::assertSame(['App\\Primary'], array_map(static fn ($usage): string => $usage->symbol(), $usages));
            self::assertSame([], $uncertainties);

            $directEvidence = new EvidenceLedger();
            $directUncertainties = [];
            $directUsages = (new SourceUsageScanner())->scan(
                $project,
                ['src/vendor'],
                $directEvidence,
                $directUncertainties,
                true
            );

            self::assertSame(['Vendor\\Dependency'], array_map(static fn ($usage): string => $usage->symbol(), $directUsages));
            self::assertSame([], $directUncertainties);
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

    /**
     * A contributed collector is third-party code running inside core's traversal. Every
     * documented way it can misbehave degrades the contributed dimension with evidence; none
     * of them may end an analysis whose Composer work already succeeded.
     *
     * @dataProvider adapterFailureProvider
     */
    public function testContributedCollectorFailuresAreContainedWithEvidence(string $mode, string $expectedReason): void
    {
        $projectPath = $this->createProject(<<<'PHP'
<?php

namespace App;

final class Example
{
    public function run(): void
    {
        \Vendor\Package\Client::send();
    }
}
PHP);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true, [
                new FixtureSourceUsageProvider('broken-adapter', $mode),
            ]);

            self::assertSame(
                [['Vendor\\Package\\Client', 'static_call']],
                array_map(static fn ($usage): array => [$usage->symbol(), $usage->usageType()], $usages)
            );

            $failures = $this->collectorFailureEvidence($evidence);
            self::assertCount(1, $failures);
            self::assertSame('source-collector-1', $failures[0]->id());
            self::assertSame(Evidence::E2_PACKAGE_METADATA, $failures[0]->evidenceClass());
            self::assertSame('high', $failures[0]->confidence());
            self::assertSame('broken-adapter', $failures[0]->context()['provider']);
            self::assertSame($expectedReason, $failures[0]->context()['reason']);
            self::assertSame('src/Example.php', $failures[0]->context()['file']);
            self::assertCount(1, $uncertainties);
            self::assertStringContainsString('broken-adapter', $uncertainties[0]);
            self::assertStringContainsString('source-collector-1', $uncertainties[0]);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    /** @return array<string, array{string, string}> */
    public function adapterFailureProvider(): array
    {
        return [
            'the provider throws' => [FixtureSourceUsageProvider::MODE_PROVIDER_THROWS, 'provider_failure'],
            'the provider yields a visitor that is not a collector' => [
                FixtureSourceUsageProvider::MODE_INVALID_COLLECTOR,
                'invalid_collector',
            ],
            'a contributed collector throws while traversing' => [
                FixtureSourceUsageProvider::MODE_COLLECTOR_THROWS,
                'collector_failure',
            ],
        ];
    }

    public function testAFailingAdapterIsReportedOnceWhileOtherAdaptersKeepContributing(): void
    {
        $projectPath = $this->createProject("<?php\nVendor\\Package\\Client::send();\n");
        file_put_contents(
            $projectPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Other.php',
            "<?php\nVendor\\Package\\Other::send();\n"
        );
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true, [
                new FixtureSourceUsageProvider('broken-adapter', FixtureSourceUsageProvider::MODE_PROVIDER_THROWS),
                new FixtureSourceUsageProvider('healthy-adapter', FixtureSourceUsageProvider::MODE_HEALTHY),
            ]);

            self::assertSame(
                [
                    ['src/Example.php', 'Vendor\\Package\\Client', 'static_call'],
                    ['src/Example.php', 'Vendor\\Package\\Client', 'fixture_static_call'],
                    ['src/Other.php', 'Vendor\\Package\\Other', 'static_call'],
                    ['src/Other.php', 'Vendor\\Package\\Other', 'fixture_static_call'],
                ],
                array_map(
                    static fn ($usage): array => [$usage->file(), $usage->symbol(), $usage->usageType()],
                    $usages
                )
            );
            self::assertCount(1, $this->collectorFailureEvidence($evidence));
            self::assertCount(1, $uncertainties);
            self::assertStringContainsString('src/Example.php', $uncertainties[0]);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    /**
     * One traverser applies a single traversal-control decision to its whole visitor list, so
     * a contributed collector that prunes its own traversal must not silently delete core's
     * usages from inside the pruned subtree. The framework-neutral inventory has to be
     * identical with and without the adapter installed.
     */
    public function testAContributedCollectorCannotTruncateTheFrameworkNeutralInventory(): void
    {
        $projectPath = $this->createProject(<<<'PHP'
<?php

namespace App;

final class Example
{
    public function run(): void
    {
        \Vendor\Package\Client::send();
        \Vendor\Package\helper();

        new \Vendor\Package\CreatedClient();
    }
}
PHP);
        $baselineEvidence = new EvidenceLedger();
        $baselineUncertainties = [];
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $baseline = (new SourceUsageScanner())->scan(
                $project,
                ['src'],
                $baselineEvidence,
                $baselineUncertainties,
                true
            );
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true, [
                new FixtureSourceUsageProvider('pruning-adapter', FixtureSourceUsageProvider::MODE_PRUNING_COLLECTOR),
            ]);
            $contributed = array_values(array_filter(
                $usages,
                static fn (SourceUsage $usage): bool => str_starts_with($usage->usageType(), 'fixture_')
            ));
            $frameworkNeutral = array_values(array_filter(
                $usages,
                static fn (SourceUsage $usage): bool => !str_starts_with($usage->usageType(), 'fixture_')
            ));

            self::assertSame(
                ['static_call', 'function_call', 'instantiated_class'],
                array_map(static fn ($usage): string => $usage->usageType(), $baseline),
                'The fixture must keep every framework-neutral usage inside the pruned class body.'
            );
            self::assertSame($this->usageProjection($baseline), $this->usageProjection($frameworkNeutral));
            self::assertSame(
                [['src/Example.php', 'Example', 'fixture_class', 5]],
                $this->usageProjection($contributed),
                'The contributed collector must still control its own traversal.'
            );
            self::assertSame([], $uncertainties);
            self::assertSame([], $this->collectorFailureEvidence($evidence));
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    /** @return list<Evidence> */
    private function collectorFailureEvidence(EvidenceLedger $evidence): array
    {
        return array_values(array_filter(
            $evidence->all(),
            static fn (Evidence $item): bool => str_starts_with($item->id(), 'source-collector-')
        ));
    }

    /**
     * @param list<SourceUsage> $usages
     * @return list<array{string, string, string, int|null}>
     */
    private function usageProjection(array $usages): array
    {
        return array_map(
            static fn (SourceUsage $usage): array => [
                $usage->file(),
                $usage->symbol(),
                $usage->usageType(),
                $usage->line(),
            ],
            $usages
        );
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

/**
 * Stands in for a third-party integration that contributes source-usage collectors. Each
 * mode reproduces one way an adapter can misbehave inside core's traversal.
 */
final class FixtureSourceUsageProvider implements FrameworkIntegration, SourceUsageVisitorProvider
{
    public const MODE_HEALTHY = 'healthy';
    public const MODE_PROVIDER_THROWS = 'provider_throws';
    public const MODE_INVALID_COLLECTOR = 'invalid_collector';
    public const MODE_COLLECTOR_THROWS = 'collector_throws';
    public const MODE_PRUNING_COLLECTOR = 'pruning_collector';

    private string $name;
    private string $mode;

    public function __construct(string $name, string $mode)
    {
        $this->name = $name;
        $this->mode = $mode;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function detect(ProjectState $project): FrameworkDetection
    {
        return new FrameworkDetection($this->name, true);
    }

    public function rules(): iterable
    {
        return [];
    }

    public function defaultSourcePaths(ProjectState $project): array
    {
        return ['src'];
    }

    public function sourceUsageVisitors(string $relativeFile): iterable
    {
        if ($this->mode === self::MODE_PROVIDER_THROWS) {
            throw new \RuntimeException('The adapter failed while building its collectors.');
        }

        if ($this->mode === self::MODE_INVALID_COLLECTOR) {
            yield new FixtureNonCollectorVisitor(); // @phpstan-ignore generator.valueType

            return;
        }

        if ($this->mode === self::MODE_COLLECTOR_THROWS) {
            yield new FixtureThrowingCollector();

            return;
        }

        if ($this->mode === self::MODE_PRUNING_COLLECTOR) {
            yield new FixturePruningCollector();

            return;
        }

        yield new FixtureRecordingCollector();
    }
}

/** A parser visitor that never satisfies the SourceUsageCollector contract. */
final class FixtureNonCollectorVisitor extends NodeVisitorAbstract
{
}

/** A contributed collector that fails while core traverses a file with it. */
final class FixtureThrowingCollector extends NodeVisitorAbstract implements SourceUsageCollector
{
    public function enterNode(Node $node)
    {
        throw new \RuntimeException('The contributed collector failed while traversing.');
    }

    /** @return list<array{symbol: string, usage_type: string, line: int}> */
    public function usages(): array
    {
        return [];
    }
}

/**
 * A contributed collector that stops descending once it has seen a class, which is an
 * ordinary optimization for a visitor that believes it owns the traversal.
 */
final class FixturePruningCollector extends NodeVisitorAbstract implements SourceUsageCollector
{
    /** @var list<array{symbol: string, usage_type: string, line: int}> */
    private array $usages = [];

    /** @return Node|int */
    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Class_) {
            $this->usages[] = [
                'symbol' => $node->name === null ? 'anonymous' : (string) $node->name,
                'usage_type' => 'fixture_class',
                'line' => $node->getStartLine(),
            ];

            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name) {
            $this->usages[] = [
                'symbol' => ltrim((string) $node->class, '\\'),
                'usage_type' => 'fixture_static_call',
                'line' => $node->getStartLine(),
            ];
        }

        return $node;
    }

    /** @return list<array{symbol: string, usage_type: string, line: int}> */
    public function usages(): array
    {
        return $this->usages;
    }
}

/** A well-behaved contributed collector with its own adapter-owned vocabulary. */
final class FixtureRecordingCollector extends NodeVisitorAbstract implements SourceUsageCollector
{
    /** @var list<array{symbol: string, usage_type: string, line: int}> */
    private array $usages = [];

    public function enterNode(Node $node): Node
    {
        if ($node instanceof Expr\StaticCall && $node->class instanceof Name) {
            $this->usages[] = [
                'symbol' => ltrim((string) $node->class, '\\'),
                'usage_type' => 'fixture_static_call',
                'line' => $node->getStartLine(),
            ];
        }

        return $node;
    }

    /** @return list<array{symbol: string, usage_type: string, line: int}> */
    public function usages(): array
    {
        return $this->usages;
    }
}
