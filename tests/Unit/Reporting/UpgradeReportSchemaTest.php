<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Reporting;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PhpUpgradePreflight\Core\Analysis\DefaultUpgradeAnalyzer;
use PhpUpgradePreflight\Core\Analysis\ReportAssembler;
use PhpUpgradePreflight\Core\Analysis\SourceImpactBuilder;
use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\ComposerDiagnostic;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\EffortEstimate;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\FrameworkGuidance;
use PhpUpgradePreflight\Core\Model\FrameworkHop;
use PhpUpgradePreflight\Core\Model\LockDiff;
use PhpUpgradePreflight\Core\Model\PackageChange;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\ReportFormat;
use PhpUpgradePreflight\Core\Model\ReportMetadata;
use PhpUpgradePreflight\Core\Model\RiskSummary;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\SourceUsage;
use PhpUpgradePreflight\Core\Model\TargetPlatformProfile;
use PhpUpgradePreflight\Core\Model\UpgradeReport;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Core\Reporting\JsonReportWriter;
use PhpUpgradePreflight\Tests\Support\JsonSnapshotNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class UpgradeReportSchemaTest extends TestCase
{
    public function testCanonicalV08ReportMatchesTheCommittedSnapshot(): void
    {
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/laravel-app';
        $actual = JsonSnapshotNormalizer::normalize(
            (new JsonReportWriter())->render($this->report($projectPath)),
            $projectPath
        );
        $snapshotPath = dirname(__DIR__, 2) . '/Snapshots/upgrade-report-v0.8.json';
        if (getenv('PHP_UPGRADE_PREFLIGHT_UPDATE_SNAPSHOTS') === '1') {
            file_put_contents($snapshotPath, $actual);
        }
        $snapshot = file_get_contents($snapshotPath);

        self::assertIsString($snapshot);
        self::assertSame($snapshot, $actual);
    }

    public function testCanonicalV08ReportConformsToThePublishedSchema(): void
    {
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/laravel-app';
        $json = (new JsonReportWriter())->render($this->report($projectPath));

        $this->assertConformsToSchema($json);
    }

    public function testRepeatedFrameworkInputProducesACanonicalSchemaConformingReport(): void
    {
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/laravel-app';
        $json = (new JsonReportWriter())->render($this->report($projectPath, ['Laravel', 'laravel']));
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['laravel'], $decoded['request_summary']['frameworks']);
        $this->assertConformsToSchema($json);
    }

    public function testPartiallyModeledExtensionsExposeMixedHostProvenance(): void
    {
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/laravel-app';
        $report = $this->report($projectPath, ['laravel'], true);
        $canonical = $report->toArray();

        self::assertSame('mixed', $canonical['platform']['extensions']['provenance']);
        self::assertSame('partial', $canonical['platform']['extensions']['completeness']);
        self::assertSame('analyzer_runtime', $canonical['platform']['extensions']['unmodeled_provenance']);
        self::assertContains(
            'Composer modeled only the listed extension assumptions; every unlisted extension still came from the analyzer runtime.',
            $report->uncertainties()
        );
        $this->assertConformsToSchema((new JsonReportWriter())->render($report));
    }

    public function testCompleteAndPartialTargetPlatformProfileProjectionsConformToSchema(): void
    {
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/laravel-app';
        $canonical = $this->report($projectPath)->toArray();
        $profile = [
            'schema_version' => '1.0',
            'completeness' => 'complete',
            'sha256' => str_repeat('a', 64),
            'provenance' => 'file',
            'supported_classes' => ['php', 'extension', 'library', 'php_subtype', 'composer_platform'],
            'closed_world' => true,
            'toolchain_bound' => ['composer', 'composer-plugin-api', 'composer-runtime-api'],
            'effective' => [
                [
                    'name' => 'composer',
                    'class' => 'composer_platform',
                    'state' => 'present',
                    'version' => '2.8.12',
                    'provenance' => 'profile',
                    'simulation' => 'toolchain_bound',
                ],
                [
                    'name' => 'composer-plugin-api',
                    'class' => 'composer_platform',
                    'state' => 'present',
                    'version' => '2.6.0',
                    'provenance' => 'profile',
                    'simulation' => 'toolchain_bound',
                ],
                [
                    'name' => 'composer-runtime-api',
                    'class' => 'composer_platform',
                    'state' => 'present',
                    'version' => '2.2.2',
                    'provenance' => 'profile',
                    'simulation' => 'toolchain_bound',
                ],
                [
                    'name' => 'ext-curl',
                    'class' => 'extension',
                    'state' => 'absent',
                    'version' => null,
                    'provenance' => 'closed_world',
                    'simulation' => 'composer_config',
                ],
                [
                    'name' => 'php',
                    'class' => 'php',
                    'state' => 'present',
                    'version' => '8.3.0',
                    'provenance' => 'profile',
                    'simulation' => 'composer_config',
                ],
            ],
        ];
        $canonical['request_summary']['target_platform_profile'] = array_intersect_key(
            $profile,
            array_flip(['schema_version', 'completeness', 'sha256', 'provenance'])
        );
        $canonical['platform']['profile'] = $profile;
        $completeJson = json_encode($canonical, JSON_THROW_ON_ERROR);

        $this->assertConformsToSchema($completeJson);

        $canonical['request_summary']['target_platform_profile']['completeness'] = 'partial';
        $canonical['platform']['profile']['completeness'] = 'partial';
        $canonical['platform']['profile']['closed_world'] = false;
        $this->assertConformsToSchema(json_encode($canonical, JSON_THROW_ON_ERROR));

        $presenceOnly = [
            'name' => 'ext-intl',
            'class' => 'extension',
            'state' => 'present',
            'version' => null,
            'provenance' => 'request',
            'simulation' => 'composer_config',
        ];
        array_splice($canonical['platform']['profile']['effective'], 4, 0, [$presenceOnly]);
        $this->assertConformsToSchema(json_encode($canonical, JSON_THROW_ON_ERROR));
        array_splice($canonical['platform']['profile']['effective'], 4, 1);

        $canonical['request_summary']['target_platform_profile']['completeness'] = 'complete';
        $canonical['platform']['profile']['completeness'] = 'complete';
        $schemaContents = file_get_contents(dirname(__DIR__, 3) . '/resources/schema/upgrade-report-v0.8.schema.json');
        self::assertIsString($schemaContents);
        $result = (new Validator(null, 20, false))->validate(
            json_decode(json_encode($canonical, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            json_decode($schemaContents, false, 512, JSON_THROW_ON_ERROR)
        );
        self::assertFalse($result->isValid(), 'A complete profile must be closed-world.');

        $canonical['platform']['profile']['closed_world'] = true;
        array_splice($canonical['platform']['profile']['effective'], 4, 0, [$presenceOnly]);
        $result = (new Validator(null, 20, false))->validate(
            json_decode(json_encode($canonical, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            json_decode($schemaContents, false, 512, JSON_THROW_ON_ERROR)
        );
        self::assertFalse($result->isValid(), 'A complete profile cannot contain a presence-only decision.');
    }

    public function testRuntimeCompleteProfileProjectionConformsToSchema(): void
    {
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/laravel-app';
        $profile = TargetPlatformProfile::fromArray([
            'schema_version' => '1.0',
            'completeness' => 'complete',
            'packages' => ['php' => '8.1'],
        ]);
        $canonical = $this->report($projectPath, ['laravel'], false, $profile)->toArray();

        self::assertSame(
            ['composer', 'composer-plugin-api', 'composer-runtime-api'],
            $canonical['platform']['profile']['toolchain_bound']
        );
        $this->assertConformsToSchema(json_encode($canonical, JSON_THROW_ON_ERROR));
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
        $contents = file_get_contents(dirname(__DIR__, 3) . '/resources/schema/upgrade-report-v0.8.schema.json');

        self::assertIsString($contents);
        /** @var array<string, mixed> $schema */
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertSame('urn:php-upgrade-preflight:schema:upgrade-report:0.8', $schema['$id']);
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
            $schema['$defs']['scenarioOutcome']['enum']
        );
        // Scenario results and Composer diagnostics must share one outcome vocabulary, so a new
        // outcome cannot be accepted in one place and rejected in the other.
        self::assertSame(
            '#/$defs/scenarioOutcome',
            $schema['$defs']['scenario']['properties']['outcome']['$ref']
        );
        self::assertSame(
            '#/$defs/scenarioOutcome',
            $schema['$defs']['composerDiagnostic']['properties']['outcome']['$ref']
        );
        $projectPath = dirname(__DIR__, 5) . '/tests/fixtures/laravel-app';
        self::assertSame(array_keys($this->report($projectPath)->toArray()), $schema['required']);
        self::assertSame(
            array_keys($this->report($projectPath)->toArray()['transition']['package_changes'][0]),
            $schema['$defs']['packageChange']['required']
        );
        self::assertSame(
            array_keys($this->report($projectPath)->toArray()['blockers'][0]),
            $schema['$defs']['blocker']['required']
        );
        self::assertSame(
            array_keys($this->report($projectPath)->toArray()['source_impact'][0]),
            $schema['$defs']['sourceImpactFinding']['required']
        );
        self::assertSame(
            array_keys($this->report($projectPath)->toArray()['framework_findings'][0]),
            $schema['$defs']['frameworkFinding']['required']
        );
        self::assertSame(1, $schema['$defs']['frameworkFinding']['properties']['applies_to_hops']['minItems']);
        self::assertSame(
            array_keys($this->report($projectPath)->toArray()['platform']['extensions']),
            $schema['$defs']['platformProvenance']['properties']['extensions']['required']
        );
        self::assertSame(
            array_keys($this->report($projectPath)->toArray()['platform']),
            $schema['$defs']['platformProvenance']['required']
        );
        self::assertSame(
            array_keys($this->report($projectPath)->toArray()['request_summary']),
            $schema['$defs']['requestSummary']['required']
        );
        self::assertNotSame([], $this->report($projectPath)->toArray()['transition']['framework_guidance']);
    }

    public function testV08SchemaUsesComposerCompatiblePlatformPackageNameGrammar(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/resources/schema/upgrade-report-v0.8.schema.json');

        self::assertIsString($contents);
        /** @var array<string, mixed> $schema */
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $platformConditions = $schema['$defs']['effectivePlatformDecision']['allOf'];
        $extensionPattern = $platformConditions[3]['then']['properties']['name']['pattern'];
        $libraryPattern = $platformConditions[4]['then']['properties']['name']['pattern'];
        $assumptionPattern = $schema['$defs']['extensionAssumption']['properties']['name']['pattern'];

        self::assertSame($extensionPattern, $assumptionPattern);
        self::assertSame(1, preg_match('/' . $extensionPattern . '/D', 'ext-pdo_sqlite'));
        self::assertSame(0, preg_match('/' . $extensionPattern . '/D', 'ext-a..b'));
        self::assertSame(1, preg_match('/' . $libraryPattern . '/D', 'lib-curl-openssl'));
        self::assertSame(0, preg_match('/' . $libraryPattern . '/D', 'lib-a--b'));
    }

    public function testCanonicalV06SnapshotStillConformsToThePreservedSchema(): void
    {
        $snapshot = file_get_contents(dirname(__DIR__, 2) . '/Snapshots/upgrade-report-v0.6.json');

        self::assertIsString($snapshot);
        $this->assertConformsToSchema($snapshot, '0.6');
    }

    public function testCanonicalV07SnapshotStillConformsToThePreservedSchema(): void
    {
        $snapshot = file_get_contents(dirname(__DIR__, 2) . '/Snapshots/upgrade-report-v0.7.json');

        self::assertIsString($snapshot);
        $this->assertConformsToSchema($snapshot, '0.7');
    }

    public function testPublishedV05BlockerContractRemainsUnchanged(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/resources/schema/upgrade-report-v0.5.schema.json');

        self::assertIsString($contents);
        /** @var array<string, mixed> $schema */
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $expectedFields = ['type', 'subject', 'summary', 'confidence', 'evidence'];

        self::assertSame('urn:php-upgrade-preflight:schema:upgrade-report:0.5', $schema['$id']);
        self::assertSame('0.5', $schema['$defs']['metadata']['properties']['schema_version']['const']);
        self::assertSame($expectedFields, $schema['$defs']['blocker']['required']);
        self::assertSame($expectedFields, array_keys($schema['$defs']['blocker']['properties']));
    }

    public function testCanonicalV05SnapshotStillConformsToThePreservedSchema(): void
    {
        $snapshot = file_get_contents(dirname(__DIR__, 2) . '/Snapshots/upgrade-report-v0.5.json');

        self::assertIsString($snapshot);
        $this->assertConformsToSchema($snapshot, '0.5');
    }

    public function testPublishedV04PackageChangeContractRemainsUnchanged(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/resources/schema/upgrade-report-v0.4.schema.json');

        self::assertIsString($contents);
        /** @var array<string, mixed> $schema */
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $expectedFields = [
            'name',
            'change_type',
            'from_version',
            'to_version',
            'direct',
            'major_change',
            'from_source_reference',
            'to_source_reference',
            'from_dist_reference',
            'to_dist_reference',
        ];

        self::assertSame('urn:php-upgrade-preflight:schema:upgrade-report:0.4', $schema['$id']);
        self::assertSame('0.4', $schema['$defs']['metadata']['properties']['schema_version']['const']);
        self::assertSame($expectedFields, $schema['$defs']['packageChange']['required']);
        self::assertSame($expectedFields, array_keys($schema['$defs']['packageChange']['properties']));
    }

    public function testCanonicalV04SnapshotStillConformsToThePreservedSchema(): void
    {
        $snapshot = file_get_contents(dirname(__DIR__, 2) . '/Snapshots/upgrade-report-v0.4.json');

        self::assertIsString($snapshot);
        $this->assertConformsToSchema($snapshot, '0.4');
    }

    public function testPublishedV03PackageChangeContractRemainsUnchanged(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/resources/schema/upgrade-report-v0.3.schema.json');

        self::assertIsString($contents);
        /** @var array<string, mixed> $schema */
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $expectedFields = [
            'name',
            'change_type',
            'from_version',
            'to_version',
            'major_change',
            'from_source_reference',
            'to_source_reference',
            'from_dist_reference',
            'to_dist_reference',
        ];

        self::assertSame('urn:php-upgrade-preflight:schema:upgrade-report:0.3', $schema['$id']);
        self::assertSame('0.3', $schema['$defs']['metadata']['properties']['schema_version']['const']);
        self::assertSame($expectedFields, $schema['$defs']['packageChange']['required']);
        self::assertSame($expectedFields, array_keys($schema['$defs']['packageChange']['properties']));
    }

    public function testCanonicalV03SnapshotStillConformsToThePreservedSchema(): void
    {
        $snapshot = file_get_contents(dirname(__DIR__, 2) . '/Snapshots/upgrade-report-v0.3.json');

        self::assertIsString($snapshot);
        $this->assertConformsToSchema($snapshot, '0.3');
    }

    public function testPublishedV02PackageChangeContractRemainsUnchanged(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/resources/schema/upgrade-report-v0.2.schema.json');

        self::assertIsString($contents);
        /** @var array<string, mixed> $schema */
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $expectedFields = ['name', 'change_type', 'from_version', 'to_version', 'major_change'];

        self::assertSame('urn:php-upgrade-preflight:schema:upgrade-report:0.2', $schema['$id']);
        self::assertSame('0.2', $schema['$defs']['metadata']['properties']['schema_version']['const']);
        self::assertSame($expectedFields, $schema['$defs']['packageChange']['required']);
        self::assertSame($expectedFields, array_keys($schema['$defs']['packageChange']['properties']));
    }

    public function testCanonicalV02SnapshotStillConformsToThePreservedSchema(): void
    {
        $snapshot = file_get_contents(dirname(__DIR__, 2) . '/Snapshots/upgrade-report-v0.2.json');

        self::assertIsString($snapshot);
        $this->assertConformsToSchema($snapshot, '0.2');
    }

    /** @param list<string> $frameworks */
    private function report(
        string $projectPath,
        array $frameworks = ['laravel'],
        bool $partialExtensions = false,
        ?TargetPlatformProfile $profile = null
    ): UpgradeReport {
        $request = new UpgradeRequest(
            $projectPath,
            [
                new UpgradeTarget('laravel/framework', '^9.0'),
                new UpgradeTarget('php', '8.1'),
            ],
            '7.4',
            null,
            ['app'],
            $frameworks,
            ReportFormat::JSON,
            null,
            false,
            [],
            $profile
        );
        $platform = ['php' => '7.4.33'];
        if ($partialExtensions) {
            $platform['ext-json'] = '8.0.0';
        }
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
                    'platform' => $platform,
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
            new Evidence('transition-1', Evidence::E4_MAINTAINER_DOCUMENTATION, 'Laravel 7 to 9 guidance is available.', 'medium', [
                'source' => 'https://laravel.com/docs/9.x/upgrade',
            ]),
        ];

        $sourceInventory = [
            new SourceUsage('app/Example.php', 'Legacy\\Facade', 'static_call', ['source-1'], 17),
        ];
        $frameworkFindings = [
            new CompatibilityFinding(
                'laravel',
                'medium',
                'Legacy package requires review.',
                ['package-1', 'source-1'],
                [['from_major' => 7, 'to_major' => 9]]
            ),
        ];

        return (new ReportAssembler())->assemble(
            $request,
            $project,
            [$scenarioResult],
            new LockDiff([
                new PackageChange(
                    'laravel/framework',
                    'upgraded',
                    'v7.30.6',
                    'v9.52.16',
                    true,
                    'source-before',
                    'source-after',
                    'dist-before',
                    'dist-after',
                    true,
                    ['laravel']
                ),
            ]),
            [
                new Blocker(
                    'root-constraint-conflict',
                    'legacy/package',
                    'A legacy root constraint must be reviewed.',
                    'high',
                    ['solver-1'],
                    '^9.0',
                    'legacy/package',
                    '1.0.0',
                    '^7.0',
                    ['legacy/package', 'laravel/framework'],
                    ['Upgrade or replace `legacy/package`.', 'Choose a compatible Laravel target.']
                ),
            ],
            $sourceInventory,
            (new SourceImpactBuilder())->build($sourceInventory, $frameworkFindings),
            $frameworkFindings,
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
            new EvidenceLedger($evidence),
            [new FrameworkGuidance(
                'laravel',
                7,
                9,
                FrameworkGuidance::SUPPORTED,
                [new FrameworkHop(7, 9, FrameworkHop::SUPPORTED, 'laravel-7-to-9-direct', ['transition-1'])],
                [],
                ['transition-1']
            )]
        );
    }

    private function assertConformsToSchema(string $json, string $schemaVersion = '0.8'): void
    {
        $schemaContents = file_get_contents(sprintf(
            '%s/resources/schema/upgrade-report-v%s.schema.json',
            dirname(__DIR__, 3),
            $schemaVersion
        ));

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
