<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Source;

use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PhpUpgradePreflight\Core\Source\AutoloadOwnershipIndexBuilder;
use PhpUpgradePreflight\Core\Source\SymbolOwnershipIndex;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AutoloadOwnershipIndexBuilderTest extends TestCase
{
    public function testItIndexesRootAndLockedStaticAutoloadMetadataWithoutLoadingCode(): void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'autoload-ownership-' . bin2hex(random_bytes(8));
        mkdir($projectPath . '/vendor/vendor/classmapped/src', 0700, true);
        mkdir($projectPath . '/vendor/vendor/classmapped/Tests', 0700, true);
        mkdir($projectPath . '/vendor/vendor/files', 0700, true);
        file_put_contents($projectPath . '/vendor/vendor/classmapped/src/LegacyClient.php', <<<'PHP'
<?php

namespace Legacy;

final class Client {}
PHP);
        file_put_contents($projectPath . '/vendor/vendor/classmapped/Tests/Excluded.php', <<<'PHP'
<?php

namespace Legacy\Tests;

final class Excluded {}
PHP);
        file_put_contents($projectPath . '/vendor/vendor/files/functions.php', <<<'PHP'
<?php

namespace Vendor\Helpers;

function format_value(): string { return 'ok'; }
const FORMAT = 'json';
spl_autoload_register(static function (): void {});
PHP);
        file_put_contents($projectPath . '/composer.json', json_encode([
            'name' => 'fixture/root-project',
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($projectPath . '/composer.lock', json_encode([
            'packages' => [[
                'name' => 'vendor/library',
                'version' => '1.0.0',
                'autoload' => [
                    'psr-4' => ['Vendor\\Library\\' => 'src/'],
                    'psr-0' => ['Legacy_' => 'legacy/'],
                ],
                'autoload-dev' => ['psr-4' => ['Vendor\\LibraryTests\\' => 'tests/']],
            ], [
                'name' => 'vendor/classmapped',
                'version' => '1.0.0',
                'autoload' => [
                    'classmap' => ['src/', 'Tests/'],
                    'files' => ['../files/functions.php'],
                    'exclude-from-classmap' => ['/Tests/'],
                ],
            ]],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $project = (new ProjectStateBuilder())->build($projectPath);
            self::assertSame(['psr-4' => ['Vendor\\Library\\' => 'src/'], 'psr-0' => ['Legacy_' => 'legacy/']], $project->composerLock()->package('vendor/library')->autoload());

            $uncertainties = [];
            $index = (new AutoloadOwnershipIndexBuilder())->build($project, $uncertainties, [
                'Legacy\\Client',
                'Vendor\\Helpers\\format_value',
                'Vendor\\Helpers\\FORMAT',
            ]);

            self::assertSame([SymbolOwnershipIndex::ROOT_OWNER], $index->lookup('App\\Service')['owners']);
            self::assertSame([SymbolOwnershipIndex::ROOT_OWNER], $index->lookup('Tests\\FeatureTest')['owners']);
            self::assertSame(['vendor/library'], $index->lookup('Vendor\\Library\\Client')['owners']);
            self::assertSame([], $index->lookup('Vendor\\LibraryTests\\ClientTest')['owners']);
            self::assertSame(['vendor/library'], $index->lookup('Legacy_Client')['owners']);
            self::assertSame(['vendor/classmapped'], $index->lookup('Legacy\\Client')['owners']);
            self::assertSame([], $index->lookup('Legacy\\Tests\\Excluded')['owners']);
            self::assertSame(['vendor/classmapped'], $index->lookup('VENDOR\\HELPERS\\FORMAT_VALUE', false, 'function')['owners']);
            self::assertSame([], $index->lookup('Vendor\\Helpers\\format_value', false, 'class')['owners']);
            self::assertSame(['vendor/classmapped'], $index->lookup('Vendor\\Helpers\\FORMAT', false, 'constant')['owners']);
            self::assertSame([], $index->lookup('Vendor\\Helpers\\format', false, 'constant')['owners']);
            self::assertCount(1, $uncertainties);
            self::assertStringContainsString('registers or generates symbols dynamically', $uncertainties[0]);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testUnsupportedAndUnavailableMappingsBecomeDeterministicUncertainty(): void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'autoload-ownership-' . bin2hex(random_bytes(8));
        mkdir($projectPath, 0700, true);
        file_put_contents($projectPath . '/composer.json', '{"autoload":{"psr-4":"dynamic","classmap":["generated/*.php",42]}}');
        file_put_contents($projectPath . '/composer.lock', '{"packages":[],"packages-dev":[]}');

        try {
            $uncertainties = [];
            (new AutoloadOwnershipIndexBuilder())->build((new ProjectStateBuilder())->build($projectPath), $uncertainties);

            self::assertSame([
                'Root package autoload.psr-4 metadata is not a static map and could not be indexed.',
                'Root package autoload.classmap contains a dynamic or unsupported entry and could not be indexed completely.',
                'Root package classmap mapping uses an unsupported dynamic path.',
            ], $uncertainties);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testEmptyRequestedSymbolsSkipExactMappingsButRetainPrefixOwnership(): void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'autoload-ownership-' . bin2hex(random_bytes(8));
        mkdir($projectPath, 0700, true);
        file_put_contents($projectPath . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => ['App\\' => 'src/'],
                'classmap' => ['missing/'],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($projectPath . '/composer.lock', '{"packages":[],"packages-dev":[]}');

        try {
            $uncertainties = [];
            $index = (new AutoloadOwnershipIndexBuilder())->build(
                (new ProjectStateBuilder())->build($projectPath),
                $uncertainties,
                []
            );

            self::assertSame([SymbolOwnershipIndex::ROOT_OWNER], $index->lookup('App\\Service')['owners']);
            self::assertSame([], $uncertainties);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testExactOwnershipFileLimitProducesDeterministicUncertainty(): void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'autoload-ownership-' . bin2hex(random_bytes(8));
        mkdir($projectPath . '/mapped', 0700, true);
        file_put_contents($projectPath . '/mapped/A.php', '<?php namespace Limited; final class A {}');
        file_put_contents($projectPath . '/mapped/B.php', '<?php namespace Limited; final class B {}');
        file_put_contents($projectPath . '/composer.json', json_encode([
            'autoload' => ['classmap' => ['mapped/A.php', 'mapped/B.php']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($projectPath . '/composer.lock', '{"packages":[],"packages-dev":[]}');

        try {
            $uncertainties = [];
            $index = (new AutoloadOwnershipIndexBuilder(null, 1))->build(
                (new ProjectStateBuilder())->build($projectPath),
                $uncertainties,
                ['Limited\\A', 'Limited\\B']
            );

            self::assertSame([SymbolOwnershipIndex::ROOT_OWNER], $index->lookup('Limited\\A')['owners']);
            self::assertSame([], $index->lookup('Limited\\B')['owners']);
            self::assertSame([
                'Static autoload ownership indexing reached the 1-file safety limit; remaining classmap/files mappings were not indexed.',
            ], $uncertainties);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }
}
