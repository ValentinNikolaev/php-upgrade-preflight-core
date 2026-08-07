<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Source;

use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Model\Evidence;
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
            self::assertSame([], $uncertainties);
            self::assertNotEmpty($evidence->all());
            self::assertContainsOnly('int', array_map(static fn ($usage): ?int => $usage->line(), $usages));
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
            self::assertSame('src' . DIRECTORY_SEPARATOR . 'Example.php', $evidence->all()[0]->context()['file']);
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

    public function testContextualInspectionClassifiesUpgradeSensitiveSourceUsages(): void
    {
        $projectPath = $this->createProject(<<<'PHP'
<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

config('services.mailgun.domain');
config(['services.mailgun.secret' => 'secret']);
config()->get('cache.default');
Config::get('app.timezone');
config($dynamicKey);
PHP);

        $sources = [
            'config/app.php' => <<<'PHP'
<?php

return [
    'providers' => [
        Vendor\Package\PackageServiceProvider::class,
    ],
];
PHP,
            'bootstrap/providers.php' => <<<'PHP'
<?php

return [App\Providers\BootstrapServiceProvider::class];
PHP,
            'app/Providers/AppServiceProvider.php' => <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider {}
PHP,
            'app/Http/Kernel.php' => <<<'PHP'
<?php

namespace App\Http;

final class Kernel
{
    protected $middleware = [\App\Http\Middleware\TrustHosts::class];
    protected $middlewareGroups = ['web' => [\App\Http\Middleware\EncryptCookies::class]];

    public function configure($route): void
    {
        $route->middleware([\App\Http\Middleware\Authenticate::class]);
    }
}
PHP,
            'app/Console/Kernel.php' => <<<'PHP'
<?php

namespace App\Console;

final class Kernel
{
    protected $commands = [\App\Console\Commands\RebuildIndex::class];

    public function register($app): void
    {
        $app->register(\Vendor\Package\RuntimeServiceProvider::class);
        $this->commands([\App\Console\Commands\WarmCache::class]);
    }
}
PHP,
            'app/Console/Commands/RebuildIndex.php' => <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class RebuildIndex extends Command {}
PHP,
            'tests/ExampleTest.php' => <<<'PHP'
<?php

namespace Tests;

use App\Contracts\Gateway;
use App\Services\Mailer;
use Mockery;

$this->createMock(Gateway::class);
$this->mock(Mailer::class);
Mockery::mock('overload:App\Services\LegacyClient');
Mailer::shouldReceive('send');
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
                ['src', 'app', 'bootstrap', 'config', 'tests'],
                $evidence,
                $uncertainties,
                true
            );
            $usageTriples = array_map(
                static fn ($usage): array => [$usage->file(), $usage->symbol(), $usage->usageType()],
                $usages
            );

            self::assertContains(['src' . DIRECTORY_SEPARATOR . 'Example.php', 'services.mailgun.domain', 'config_reference'], $usageTriples);
            self::assertContains(['src' . DIRECTORY_SEPARATOR . 'Example.php', 'services.mailgun.secret', 'config_reference'], $usageTriples);
            self::assertContains(['src' . DIRECTORY_SEPARATOR . 'Example.php', 'cache.default', 'config_reference'], $usageTriples);
            self::assertContains(['src' . DIRECTORY_SEPARATOR . 'Example.php', 'app.timezone', 'config_reference'], $usageTriples);
            self::assertNotContains(['src' . DIRECTORY_SEPARATOR . 'Example.php', 'dynamicKey', 'config_reference'], $usageTriples);
            self::assertContains(['config' . DIRECTORY_SEPARATOR . 'app.php', 'Vendor\Package\PackageServiceProvider', 'service_provider'], $usageTriples);
            self::assertContains(['bootstrap' . DIRECTORY_SEPARATOR . 'providers.php', 'App\Providers\BootstrapServiceProvider', 'service_provider'], $usageTriples);
            self::assertContains(['app' . DIRECTORY_SEPARATOR . 'Providers' . DIRECTORY_SEPARATOR . 'AppServiceProvider.php', 'App\Providers\AppServiceProvider', 'service_provider'], $usageTriples);
            self::assertContains(['app' . DIRECTORY_SEPARATOR . 'Console' . DIRECTORY_SEPARATOR . 'Kernel.php', 'Vendor\Package\RuntimeServiceProvider', 'service_provider'], $usageTriples);
            self::assertContains(['app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Kernel.php', 'App\Http\Middleware\TrustHosts', 'middleware_reference'], $usageTriples);
            self::assertContains(['app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Kernel.php', 'App\Http\Middleware\EncryptCookies', 'middleware_reference'], $usageTriples);
            self::assertContains(['app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Kernel.php', 'App\Http\Middleware\Authenticate', 'middleware_reference'], $usageTriples);
            self::assertContains(['app' . DIRECTORY_SEPARATOR . 'Console' . DIRECTORY_SEPARATOR . 'Kernel.php', 'App\Console\Commands\RebuildIndex', 'console_command'], $usageTriples);
            self::assertContains(['app' . DIRECTORY_SEPARATOR . 'Console' . DIRECTORY_SEPARATOR . 'Kernel.php', 'App\Console\Commands\WarmCache', 'console_command'], $usageTriples);
            self::assertContains(['app' . DIRECTORY_SEPARATOR . 'Console' . DIRECTORY_SEPARATOR . 'Commands' . DIRECTORY_SEPARATOR . 'RebuildIndex.php', 'App\Console\Commands\RebuildIndex', 'console_command'], $usageTriples);
            self::assertContains(['tests' . DIRECTORY_SEPARATOR . 'ExampleTest.php', 'App\Contracts\Gateway', 'test_double'], $usageTriples);
            self::assertContains(['tests' . DIRECTORY_SEPARATOR . 'ExampleTest.php', 'App\Services\Mailer', 'test_double'], $usageTriples);
            self::assertContains(['tests' . DIRECTORY_SEPARATOR . 'ExampleTest.php', 'App\Services\LegacyClient', 'test_double'], $usageTriples);
            self::assertSame([], $uncertainties);

            foreach ($usages as $usage) {
                if (in_array($usage->usageType(), ['config_reference', 'service_provider', 'middleware_reference', 'console_command', 'test_double'], true)) {
                    self::assertNotNull($usage->line());
                    self::assertCount(1, $usage->evidence());
                }
            }
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testConfigArrayReadsAndWritesPreserveLiteralKeys(): void
    {
        $projectPath = $this->createProject(<<<'PHP'
<?php

namespace App;

use Illuminate\Support\Facades\Config;

Config::get(['app.name', 'app.env']);
Config::getMany(['cache.default', 'queue.default']);
Config::get(['mail.default' => 'smtp']);
Config::set(['app.debug' => false]);
PHP);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);
            $configReferences = array_values(array_filter(
                $usages,
                static fn ($usage): bool => $usage->usageType() === 'config_reference'
            ));

            self::assertSame(
                ['app.name', 'app.env', 'cache.default', 'queue.default', 'mail.default', 'app.debug'],
                array_map(static fn ($usage): string => $usage->symbol(), $configReferences)
            );
            self::assertSame([], $uncertainties);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testPhpUnitAndFacadeTestDoubleApisAreClassified(): void
    {
        $projectPath = $this->createProject(<<<'PHP'
<?php

namespace Tests;

use App\Contracts\AbstractGateway;
use App\Services\PartialMailer;
use App\Support\ReusableBehavior;
use Illuminate\Support\Facades\Event;

$this->createPartialMock(PartialMailer::class, ['send']);
$this->getMockForAbstractClass(AbstractGateway::class);
$this->getMockForTrait(ReusableBehavior::class);
Event::fake();
PHP);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);
            $testDoubles = array_values(array_filter(
                $usages,
                static fn ($usage): bool => $usage->usageType() === 'test_double'
            ));

            self::assertSame(
                [
                    'App\Services\PartialMailer',
                    'App\Contracts\AbstractGateway',
                    'App\Support\ReusableBehavior',
                    'Illuminate\Support\Facades\Event',
                ],
                array_map(static fn ($usage): string => $usage->symbol(), $testDoubles)
            );
            self::assertSame([], $uncertainties);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testRegisterRequiresApplicationContextOrAServiceProviderTarget(): void
    {
        $projectPath = $this->createProject(<<<'PHP'
<?php

namespace App;

$serializer->register(\App\Serialization\JsonNormalizer::class);
$container->register(\Vendor\Package\PackageServiceProvider::class);
$app->register(\App\Providers\CustomProvider::class);
$this->application->register(\App\Providers\OtherProvider::class);
Application::register(\App\Providers\StaticProvider::class);
PHP);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            $usages = (new SourceUsageScanner())->scan($project, ['src'], $evidence, $uncertainties, true);
            $serviceProviders = array_values(array_filter(
                $usages,
                static fn ($usage): bool => $usage->usageType() === 'service_provider'
            ));
            $symbols = array_map(static fn ($usage): string => $usage->symbol(), $serviceProviders);

            self::assertNotContains('App\Serialization\JsonNormalizer', $symbols);
            self::assertSame(
                [
                    'Vendor\Package\PackageServiceProvider',
                    'App\Providers\CustomProvider',
                    'App\Providers\OtherProvider',
                    'App\Providers\StaticProvider',
                ],
                $symbols
            );
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
