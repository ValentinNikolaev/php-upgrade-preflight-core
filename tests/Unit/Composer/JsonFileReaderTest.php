<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\InvalidJsonException;
use PhpUpgradePreflight\Core\Composer\JsonFileReader;
use PhpUpgradePreflight\Core\Composer\MissingJsonFileException;
use PhpUpgradePreflight\Core\Composer\UnreadableJsonFileException;
use PHPUnit\Framework\TestCase;

final class JsonFileReaderTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    public function testItReadsAComposerObjectIntoAnAssociativeArray(): void
    {
        $path = $this->file('composer.json', '{"name":"fixture/reader","config":{"platform":{"php":"8.0.30"}}}');

        self::assertSame(
            ['name' => 'fixture/reader', 'config' => ['platform' => ['php' => '8.0.30']]],
            (new JsonFileReader())->read($path)
        );
    }

    public function testAMissingFileIsReportedWithoutItsDirectory(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'preflight-absent-composer.json';

        $this->expectException(MissingJsonFileException::class);
        $this->expectExceptionMessage('Required Composer file "preflight-absent-composer.json" was not found.');

        (new JsonFileReader())->read($path);
    }

    /**
     * A file the analyzer can see but not open is a different input failure from an
     * absent one, and the caller has to be able to tell them apart. A stream wrapper
     * produces that state on every host, unlike a permission bit.
     */
    public function testAFileThatCannotBeOpenedIsReportedAsUnreadable(): void
    {
        $scheme = 'preflight-unreadable-json';
        self::assertTrue(stream_wrapper_register($scheme, UnreadableJsonStreamWrapper::class));

        try {
            $this->expectException(UnreadableJsonFileException::class);
            $this->expectExceptionMessage('Unable to read Composer file "composer.json".');

            (new JsonFileReader())->read($scheme . '://composer.json');
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function testMalformedJsonIsReportedWithComposersOwnDiagnostic(): void
    {
        $path = $this->file('composer.json', '{"name": ');

        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage('Invalid JSON in Composer file "composer.json": Syntax error.');

        (new JsonFileReader())->read($path);
    }

    /**
     * @dataProvider validJsonThatIsNotAnObject
     */
    public function testValidJsonThatIsNotAnObjectIsRejected(string $contents): void
    {
        $path = $this->file('composer.lock', $contents);

        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage('Composer file "composer.lock" must contain a JSON object.');

        (new JsonFileReader())->read($path);
    }

    /** @return array<string, array{string}> */
    public static function validJsonThatIsNotAnObject(): array
    {
        return [
            'list' => ['["laravel/framework"]'],
            'string' => ['"laravel/framework"'],
            'number' => ['7'],
            'null' => ['null'],
        ];
    }

    public function testAManifestConfigSectionThatIsNotAnObjectIsRejected(): void
    {
        $path = $this->file('composer.json', '{"config":[]}');

        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage(
            'Composer file "composer.json" cannot be analyzed because its "config" section is not an object.'
        );

        (new JsonFileReader())->read($path);
    }

    public function testAManifestPlatformSectionThatIsNotAnObjectIsRejected(): void
    {
        $path = $this->file('composer.json', '{"config":{"platform":[]}}');

        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage(
            'Composer file "composer.json" cannot be analyzed because its "config.platform" section is not an object.'
        );

        (new JsonFileReader())->read($path);
    }

    /** A lock file is not a manifest, so the manifest-only section rules do not apply to it. */
    public function testALockNamedSectionIsNotHeldToTheManifestRules(): void
    {
        $path = $this->file('composer.lock', '{"config":[]}');

        self::assertSame(['config' => []], (new JsonFileReader())->read($path));
    }

    private function file(string $name, string $contents): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-json-reader-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the reader fixture directory.');
        }
        $this->temporaryDirectories[] = $directory;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Unable to write the reader fixture.');
        }

        return $path;
    }
}

final class UnreadableJsonStreamWrapper
{
    /** @var resource|null */
    public $context;

    /** @return array{mode: int} */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100000];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}
