<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\InvalidJsonException;
use PhpUpgradePreflight\Core\Composer\ScenarioOutcomeClassifier;
use PhpUpgradePreflight\Core\Composer\ScenarioWorkspacePreparer;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\ExtensionAssumption;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class ScenarioWorkspacePreparerTest extends TestCase
{
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

    public function testPlatformSimulationReusesTheCasingTheManifestAlreadyDeclares(): void
    {
        [$written, $candidate] = $this->prepare([
            'require' => ['fixture/dependency' => '^1.0'],
            'config' => ['platform' => [
                'PHP' => '7.4.0',
                'EXT-JSON' => '8.3.0',
            ]],
        ], ['ext-json:8.4.0']);

        self::assertSame(['PHP' => '8.2.0', 'EXT-JSON' => '8.4.0'], $written['config']['platform']);
        self::assertSame(['ext-json' => '8.4.0', 'php' => '8.2.0'], $candidate->configuredPlatformPackages());
    }

    public function testAgreeingCaseVariantsAreRewrittenTogetherSoTheyKeepAgreeing(): void
    {
        [$written, $candidate] = $this->prepare([
            'require' => ['fixture/dependency' => '^1.0'],
            'config' => ['platform' => [
                'PHP' => '7.4.0',
                'php' => '7.4.0',
                'Ext-Json' => '8.3.0',
                'ext-json' => '8.3.0',
            ]],
        ], ['ext-json:8.4.0']);

        self::assertSame([
            'PHP' => '8.2.0',
            'php' => '8.2.0',
            'Ext-Json' => '8.4.0',
            'ext-json' => '8.4.0',
        ], $written['config']['platform']);
        self::assertSame(['ext-json' => '8.4.0', 'php' => '8.2.0'], $candidate->configuredPlatformPackages());
    }

    public function testAManifestWithoutPlatformConfigurationStillReceivesLowercaseSimulationKeys(): void
    {
        [$written, $candidate] = $this->prepare([
            'require' => ['fixture/dependency' => '^1.0'],
            'config' => ['vendor-dir' => 'vendor'],
        ], ['ext-json:8.4.0']);

        self::assertSame('vendor', $written['config']['vendor-dir']);
        self::assertSame(['php' => '8.2.0', 'ext-json' => '8.4.0'], $written['config']['platform']);
        self::assertSame(['ext-json' => '8.4.0', 'php' => '8.2.0'], $candidate->configuredPlatformPackages());
    }

    /**
     * @dataProvider unusableConfigurationProvider
     * @param mixed $config
     */
    public function testAnUnusableConfigurationSectionIsReportedAsAnInvalidManifest(
        $config,
        string $reason
    ): void {
        $workspacePath = $this->createDirectory('preflight-workspace-');

        try {
            $this->prepare(['require' => ['fixture/dependency' => '^1.0'], 'config' => $config], [], $workspacePath);
            self::fail('Expected an unusable Composer configuration section to be rejected.');
        } catch (InvalidJsonException $exception) {
            self::assertSame(
                sprintf('Composer file "composer.json" cannot be analyzed because %s.', $reason),
                $exception->getMessage()
            );
            self::assertSame('composer.json', basename($exception->path()));
            self::assertSame(
                ScenarioResult::OUTCOME_INVALID_JSON,
                (new ScenarioOutcomeClassifier())
                    ->classifyException($exception, ScenarioOutcomeClassifier::PHASE_PREPARATION)
                    ->outcome()
            );
            self::assertFileDoesNotExist($workspacePath . DIRECTORY_SEPARATOR . 'composer.json');
        }
    }

    /** @return array<string, array{mixed, string}> */
    public function unusableConfigurationProvider(): array
    {
        return [
            'string configuration' => ['default', 'its "config" section is not an object'],
            'integer configuration' => [5, 'its "config" section is not an object'],
            'boolean configuration' => [true, 'its "config" section is not an object'],
            'list configuration' => [['unexpected'], 'its "config" section is not an object'],
            'string platform section' => [
                ['platform' => '8.2.0'],
                'its "config.platform" section is not an object',
            ],
            'list platform section' => [
                ['platform' => ['unexpected']],
                'its "config.platform" section is not an object',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $presentExtensions
     * @return array{array<string, mixed>, ComposerJson}
     */
    private function prepare(array $manifest, array $presentExtensions, ?string $workspacePath = null): array
    {
        $projectPath = $this->createDirectory('preflight-project-');
        $workspacePath = $workspacePath ?? $this->createDirectory('preflight-workspace-');
        $project = new ProjectState($projectPath, new ComposerJson($manifest), new ComposerLock([]));
        $request = new UpgradeRequest(
            $projectPath,
            [new UpgradeTarget('fixture/dependency', '^2.0')],
            null,
            '8.2',
            [],
            [],
            'json',
            null,
            false,
            array_map(
                static fn (string $input): ExtensionAssumption => ExtensionAssumption::fromPresenceInput($input),
                $presentExtensions
            )
        );
        $candidate = (new ScenarioWorkspacePreparer())->applyTemporaryComposerChanges(
            $workspacePath,
            $project,
            new Scenario('target-feasibility', $request->targets()),
            TargetPlatform::fromRequest($request, $project, [], '8.0.30')
        );

        $written = json_decode(
            (string) file_get_contents($workspacePath . DIRECTORY_SEPARATOR . 'composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertIsArray($written);

        return [$written, $candidate];
    }

    private function createDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        self::assertTrue(@mkdir($path, 0700));
        $this->directories[] = $path;

        return $path;
    }
}
