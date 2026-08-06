<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Reporting;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PhpUpgradePreflight\Core\Analysis\DefaultUpgradeAnalyzer;
use PhpUpgradePreflight\Core\Analysis\ReportAssembler;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\ReportMetadata;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Reporting\JsonReportWriter;
use PhpUpgradePreflight\Tests\Support\JsonSnapshotNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class UpgradeReportSchemaTest extends TestCase
{
    public function testCanonicalV02ReportMatchesTheCommittedSnapshot(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $actual = JsonSnapshotNormalizer::normalize(
            (new JsonReportWriter())->render($this->report($projectPath)),
            $projectPath
        );
        $snapshot = file_get_contents(dirname(__DIR__, 2) . '/Snapshots/upgrade-report-v0.2.json');

        self::assertIsString($snapshot);
        self::assertSame($snapshot, $actual);
    }

    public function testCanonicalV02ReportConformsToThePublishedSchema(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $json = (new JsonReportWriter())->render($this->report($projectPath));

        $this->assertConformsToSchema($json);
    }

    public function testRepeatedFrameworkInputProducesACanonicalSchemaConformingReport(): void
    {
        $projectPath = dirname(__DIR__, 5);
        $json = (new JsonReportWriter())->render($this->report($projectPath, ['Laravel', 'laravel']));
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['laravel'], $decoded['request_summary']['frameworks']);
        $this->assertConformsToSchema($json);
    }

    public function testStructuredProjectInputFailureConformsToThePublishedSchema(): void
    {
        $projectPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-upgrade-preflight-schema-input-' . bin2hex(random_bytes(8));
        mkdir($projectPath, 0700, true);
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.json', '{invalid');
        file_put_contents($projectPath . DIRECTORY_SEPARATOR . 'composer.lock', '{"packages":[]}');
        $request = new UpgradeRequest($projectPath, [new UpgradeTarget('fixture/dependency', '^2.0')]);

        try {
            $json = (new JsonReportWriter())->render((new DefaultUpgradeAnalyzer())->analyzeUpgrade($request));
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('invalid_json', $decoded['resolution']['scenarios'][0]['outcome']);
            $this->assertConformsToSchema($json);
        } finally {
            (new Filesystem())->remove($projectPath);
        }
    }

    public function testPublishedSchemaAndRuntimeMetadataDescribeTheSameContractVersion(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/resources/schema/upgrade-report-v0.2.schema.json');

        self::assertIsString($contents);
        /** @var array<string, mixed> $schema */
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertSame('urn:php-upgrade-preflight:schema:upgrade-report:0.2', $schema['$id']);
        self::assertSame(
            ReportMetadata::SCHEMA_VERSION,
            $schema['$defs']['metadata']['properties']['schema_version']['const']
        );
        self::assertSame(
            ReportMetadata::TOOL_NAME,
            $schema['$defs']['metadata']['properties']['tool']['properties']['name']['const']
        );
        self::assertSame(1, preg_match(
            '/' . $schema['$defs']['metadata']['properties']['tool']['properties']['version']['pattern'] . '/',
            ReportMetadata::TOOL_VERSION
        ));
        self::assertContains(
            ScenarioResult::FAILURE_VALIDATION,
            $schema['$defs']['scenario']['properties']['failure_type']['enum']
        );
        self::assertSame(
            ScenarioResult::supportedOutcomes(),
            $schema['$defs']['scenario']['properties']['outcome']['enum']
        );
        self::assertSame(array_keys($this->report(dirname(__DIR__, 5))->toArray()), $schema['required']);
    }

    /** @param list<string> $frameworks */
    private function report(string $projectPath, array $frameworks = ['laravel']): UpgradeReport
    {
        $request = new UpgradeRequest(
            $projectPath,
            [
                new UpgradeTarget('laravel/framework', '^9.0'),
                new UpgradeTarget('php', '8.1'),
            ],
            '7.4',
            null,
            ['app'],
            $frameworks
        );
        $project = new ProjectState(
            $projectPath,
            new ComposerJson([
                'require' => [
                    'php' => '^7.4',
                    'laravel/framework' => '^7.0',
                ],
                'require-dev' => [
                    'phpunit/phpunit' => '^8.5',
                ],
                'config' => [
                    'platform' => [
                        'php' => '7.4.33',
                    ],
                ],
                'scripts' => [
                    'test' => 'phpunit',
                ],
            ]),
            new ComposerLock([
                'packages' => [
                    ['name' => 'laravel/framework', 'version' => 'v7.30.6'],
                    ['name' => 'symfony/console', 'version' => 'v5.4.0'],
                ],
            ])
        );
        $scenario = new Scenario('all-dependencies', $request->targets());
        $scenarioResult = new ScenarioResult(
            $scenario,
            0,
            'Composer resolved the requested target.',
            '',
            new ComposerLock([
                'packages' => [
                    ['name' => 'laravel/framework', 'version' => 'v9.52.16'],
                ],
            ]),
            null,
            null,
            '2.8.12',
            ['composer', 'update', 'laravel/framework', '--with-all-dependencies', '--no-scripts', '--no-plugins', '--no-install', '--no-interaction'],
            125,
            null,
            [new ComposerDiagnostic(
                'laravel/framework',
                '^9.0',
                ['composer', 'prohibits', 'laravel/framework', '^9.0', '--tree', '--locked', '--no-plugins', '--no-interaction'],
                0,
                'legacy/package 1.0.0 requires illuminate/support (^7.0)',
                ''
            )]
        );
        $evidence = [
            new Evidence('solver-1', Evidence::E1_SOLVER, 'Composer reported a root constraint conflict.', 'high', [
                'scenario' => 'strict-target',
                'package' => 'legacy/package',
            ]),
            new Evidence('source-1', Evidence::E3_PROJECT_SOURCE, 'Detected a legacy facade call.', 'high', [
                'file' => 'app/Example.php',
                'line' => 17,
                'usage_type' => 'static_call',
            ]),
            new Evidence('package-1', Evidence::E2_PACKAGE_METADATA, 'Legacy package metadata requires review.', 'medium', [
                'package' => 'legacy/package',
                'locked_version' => '1.0.0',
            ]),
        ];

        return (new ReportAssembler())->assemble(
            $request,
            $project,
            [$scenarioResult],
            new LockDiff([
                new PackageChange('laravel/framework', 'upgraded', 'v7.30.6', 'v9.52.16', true),
            ]),
            [
                new Blocker(
                    'root-constraint-conflict',
                    'legacy/package',
                    'A legacy root constraint must be reviewed.',
                    'high',
                    ['solver-1']
                ),
            ],
            [
                new SourceUsage('app/Example.php', 'Legacy\\Facade', 'static_call', ['source-1'], 17),
            ],
            [
                new CompatibilityFinding('laravel', 'medium', 'Legacy package requires review.', ['package-1']),
            ],
            new RiskSummary('high', [
                'A root dependency constraint conflicts with the requested target.',
                'A framework compatibility finding requires review.',
            ]),
            new EffortEstimate(
                [8, 20],
                'low',
                [
                    'dependency_resolution' => [3, 8],
                    'source_changes' => [2, 4],
                    'tests_and_debugging' => [3, 8],
                ],
                ['The project test suite is available and representative.']
            ),
            [],
            new EvidenceLedger($evidence)
        );
    }

    private function assertConformsToSchema(string $json): void
    {
        $schemaContents = file_get_contents(dirname(__DIR__, 3) . '/resources/schema/upgrade-report-v0.2.schema.json');

        self::assertIsString($schemaContents);
        /** @var object $schema */
        $schema = json_decode($schemaContents, false, 512, JSON_THROW_ON_ERROR);
        /** @var mixed $report */
        $report = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $result = (new Validator(null, 20, false))->validate($report, $schema);

        if ($result->hasError()) {
            $error = $result->error();
            self::assertNotNull($error);
            self::fail(json_encode(
                (new ErrorFormatter())->format($error),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
        }

        self::assertTrue($result->isValid());
    }
}
