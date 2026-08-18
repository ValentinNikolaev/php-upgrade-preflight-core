<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\InvalidJsonException;
use PhpUpgradePreflight\Core\Composer\JsonFileException;
use PhpUpgradePreflight\Core\Composer\MissingJsonFileException;
use PhpUpgradePreflight\Core\Composer\ProjectStateBuilder;
use PHPUnit\Framework\TestCase;

final class ProjectStateBuilderTest extends TestCase
{
    private const CONTRADICTORY_MANIFEST = [
        'require' => ['fixture/dependency' => '^1.0'],
        'config' => ['platform' => ['php' => '8.1.0', 'PHP' => '8.2.0']],
    ];

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach ((array) glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($directory);
        }
        $this->directories = [];

        parent::tearDown();
    }

    /** @dataProvider lockfilePresenceProvider */
    public function testAContradictoryManifestBecomesAStructuredInputFailure(bool $withLockfile): void
    {
        $projectPath = $this->createProject(self::CONTRADICTORY_MANIFEST, $withLockfile);

        $result = (new ProjectStateBuilder())->load($projectPath);
        $failure = $result->failure();

        self::assertFalse($result->succeeded());
        // InvalidJsonException is what DefaultUpgradeAnalyzer maps to the canonical
        // invalid_json input-failure report.
        self::assertInstanceOf(InvalidJsonException::class, $failure);
        self::assertSame(
            'Invalid Composer file "composer.json": Project config.platform contains contradictory duplicate package names.',
            $failure->getMessage()
        );
        self::assertSame('composer.json', basename($failure->path()));
        self::assertSame($projectPath, $result->project()->path());
        self::assertSame([], $result->project()->composerJson()->configuredPlatformPackages());
    }

    /** @return array<string, array{bool}> */
    public function lockfilePresenceProvider(): array
    {
        return [
            'lockfile present' => [true],
            'lockfile missing' => [false],
        ];
    }

    public function testBuildRethrowsTheStructuredManifestFailure(): void
    {
        $projectPath = $this->createProject(self::CONTRADICTORY_MANIFEST, true);

        $this->expectException(JsonFileException::class);
        $this->expectExceptionMessage('Project config.platform contains contradictory duplicate package names.');

        (new ProjectStateBuilder())->build($projectPath);
    }

    public function testAMissingLockfileStillPublishesTheLoadedManifest(): void
    {
        $projectPath = $this->createProject([
            'require' => ['fixture/dependency' => '^1.0'],
            'config' => ['platform' => ['php' => '8.1.0']],
        ], false);

        $result = (new ProjectStateBuilder())->load($projectPath);

        self::assertFalse($result->succeeded());
        self::assertInstanceOf(MissingJsonFileException::class, $result->failure());
        self::assertSame('8.1.0', $result->project()->composerJson()->platformPhp());
    }

    public function testAValidProjectLoadsWithoutFailure(): void
    {
        $projectPath = $this->createProject([
            'require' => ['fixture/dependency' => '^1.0'],
            'config' => ['platform' => ['php' => '8.1.0', 'PHP' => '8.1.0']],
        ], true);

        $result = (new ProjectStateBuilder())->load($projectPath);

        self::assertTrue($result->succeeded());
        self::assertNull($result->failure());
        self::assertSame(['php' => '8.1.0'], $result->project()->composerJson()->configuredPlatformPackages());
        self::assertSame(['fixture/dependency' => '^1.0'], $result->project()->composerJson()->rootRequirements());
    }

    /** @dataProvider nonObjectConfigurationProvider */
    public function testJsonArraysCannotMasqueradeAsConfigurationObjects(string $manifest, string $reason): void
    {
        $projectPath = $this->createProjectFromJson($manifest, true);

        $result = (new ProjectStateBuilder())->load($projectPath);
        $failure = $result->failure();

        self::assertFalse($result->succeeded());
        self::assertInstanceOf(InvalidJsonException::class, $failure);
        self::assertSame(
            sprintf('Composer file "composer.json" cannot be analyzed because %s.', $reason),
            $failure->getMessage()
        );
    }

    /** @return iterable<string, array{string, string}> */
    public function nonObjectConfigurationProvider(): iterable
    {
        yield 'non-empty config list' => [
            '{"require":{"fixture/dependency":"^1.0"},"config":["unexpected"]}',
            'its "config" section is not an object',
        ];
        yield 'empty config list' => [
            '{"require":{"fixture/dependency":"^1.0"},"config":[]}',
            'its "config" section is not an object',
        ];
        yield 'non-empty platform list' => [
            '{"require":{"fixture/dependency":"^1.0"},"config":{"platform":["unexpected"]}}',
            'its "config.platform" section is not an object',
        ];
        yield 'empty platform list' => [
            '{"require":{"fixture/dependency":"^1.0"},"config":{"platform":[]}}',
            'its "config.platform" section is not an object',
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function createProject(array $manifest, bool $withLockfile): string
    {
        return $this->createProjectFromJson(
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $withLockfile
        );
    }

    private function createProjectFromJson(string $manifest, bool $withLockfile): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'preflight-project-state-' . bin2hex(random_bytes(8));
        self::assertTrue(@mkdir($path, 0700));
        $this->directories[] = $path;

        self::assertNotFalse(file_put_contents(
            $path . DIRECTORY_SEPARATOR . 'composer.json',
            $manifest
        ));

        if ($withLockfile) {
            self::assertNotFalse(file_put_contents(
                $path . DIRECTORY_SEPARATOR . 'composer.lock',
                json_encode([
                    'content-hash' => 'fixture-content-hash',
                    'packages' => [['name' => 'fixture/dependency', 'version' => '1.0.0']],
                    'packages-dev' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            ));
        }

        return $path;
    }
}
