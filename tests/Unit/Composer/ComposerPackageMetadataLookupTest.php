<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\ComposerPackageMetadataLookup;
use PhpUpgradePreflight\Core\Composer\PackageMetadataLookupMode;
use PhpUpgradePreflight\Core\Composer\PackageMetadataLookupResult;
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ComposerPackageMetadataLookupTest extends TestCase
{
    public function testItFindsAPackageAndMatchesTheRequestedConstraint(): void
    {
        $observed = [];
        $lookup = new ComposerPackageMetadataLookup(
            static function (array $command, string $directory, array $environment, int $timeout) use (&$observed): array {
                $observed = compact('command', 'directory', 'environment', 'timeout');

                return [
                    'exit_code' => 0,
                    'stdout' => json_encode([
                        'name' => 'vendor/package',
                        'versions' => ['3.0.0', '2.1.0', '* 2.0.0', '1.9.0'],
                    ], JSON_THROW_ON_ERROR),
                    'stderr' => '',
                ];
            }
        );
        $execution = new ComposerExecutionConfiguration('custom-composer', '>=2.0', 300, 17);

        $result = $lookup->lookup(
            $this->projectPath(),
            ' Vendor/Package ',
            '^2.0',
            $execution,
            PackageMetadataLookupMode::PROJECT_REPOSITORIES
        );

        self::assertSame(PackageMetadataLookupResult::STATUS_FOUND, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_PACKAGE_FOUND, $result->reason());
        self::assertTrue($result->hasMatchingVersion());
        self::assertSame(['2.1.0', '2.0.0'], $result->matchingVersions());
        self::assertSame(4, $result->availableVersionCount());
        self::assertSame(2, $result->matchingVersionCount());
        self::assertSame('custom-composer', $observed['command'][0]);
        self::assertSame('show', $observed['command'][1]);
        self::assertSame('vendor/package', $observed['command'][2]);
        self::assertContains('--no-interaction', $observed['command']);
        self::assertContains('--no-plugins', $observed['command']);
        self::assertContains('--no-scripts', $observed['command']);
        self::assertSame(false, $observed['environment']['COMPOSER']);
        self::assertSame(false, $observed['environment']['COMPOSER_DISABLE_NETWORK']);
        self::assertSame('1', $observed['environment']['COMPOSER_NO_INTERACTION']);
        self::assertSame(17, $observed['timeout']);
        self::assertSame($this->projectPath(), $observed['directory']);
    }

    public function testFoundPackageCanHaveNoVersionMatchingTheConstraint(): void
    {
        $lookup = $this->lookupReturning(0, json_encode([
            'name' => 'vendor/package',
            'versions' => ['2.1.0', '2.0.0'],
        ], JSON_THROW_ON_ERROR));

        $result = $this->lookup($lookup, '^3.0');

        self::assertSame(PackageMetadataLookupResult::STATUS_FOUND, $result->status());
        self::assertFalse($result->hasMatchingVersion());
        self::assertSame([], $result->matchingVersions());
        self::assertSame(0, $result->matchingVersionCount());
    }

    public function testEmptyDuplicateAndUnparseableVersionsAreHandledWithoutInventingMatches(): void
    {
        $lookup = $this->lookupReturning(0, json_encode([
            'name' => 'vendor/package',
            'versions' => ['', '  ', '2.0.0', '* 2.0.0', 'not-a-semver-version'],
        ], JSON_THROW_ON_ERROR));

        $result = $this->lookup($lookup, '^2.0');

        self::assertSame(PackageMetadataLookupResult::STATUS_FOUND, $result->status());
        self::assertSame(['2.0.0', 'not-a-semver-version'], $result->versions());
        self::assertSame(['2.0.0'], $result->matchingVersions());
        self::assertSame(2, $result->availableVersionCount());
        self::assertSame(1, $result->matchingVersionCount());
    }

    public function testProjectRepositoryLookupCanReportAnExplicitPackageNotFoundResult(): void
    {
        $lookup = $this->lookupReturning(
            1,
            '',
            'Package "vendor/package" not found, try using --available to show all available packages.'
        );

        $result = $this->lookup($lookup, '^2.0');

        self::assertSame(PackageMetadataLookupResult::STATUS_NOT_FOUND, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_PACKAGE_NOT_FOUND, $result->reason());
        self::assertNull($result->hasMatchingVersion());
    }

    public function testLocalCacheMissIsUnverifiedInsteadOfClaimingThePackageDoesNotExist(): void
    {
        $observedEnvironment = [];
        $lookup = new ComposerPackageMetadataLookup(
            static function (array $command, string $directory, array $environment) use (&$observedEnvironment): array {
                $observedEnvironment = $environment;

                return [
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => 'Package "vendor/package" not found.',
                ];
            }
        );

        $result = $lookup->lookup(
            $this->projectPath(),
            'vendor/package',
            '^2.0',
            ComposerExecutionConfiguration::compatible(),
            PackageMetadataLookupMode::LOCAL_CACHE_ONLY
        );

        self::assertSame(PackageMetadataLookupResult::STATUS_UNVERIFIED, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_LOCAL_METADATA_UNAVAILABLE, $result->reason());
        self::assertSame('1', $observedEnvironment['COMPOSER_DISABLE_NETWORK']);
    }

    /**
     * @dataProvider lookupModeProvider
     */
    public function testRestrictedExecutionDoesNotStartAnUnisolatedLookupProcess(string $mode): void
    {
        $calls = 0;
        $lookup = new ComposerPackageMetadataLookup(
            static function () use (&$calls): array {
                ++$calls;

                return ['exit_code' => 0, 'stdout' => '{}', 'stderr' => ''];
            }
        );

        $result = $lookup->lookup(
            $this->projectPath(),
            'vendor/package',
            '^2.0',
            ComposerExecutionConfiguration::restricted(),
            $mode
        );

        self::assertSame(PackageMetadataLookupResult::STATUS_UNVERIFIED, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_RESTRICTED_EXECUTION_UNAVAILABLE, $result->reason());
        self::assertSame(0, $calls);
    }

    /** @return list<array{string}> */
    public function lookupModeProvider(): array
    {
        return [
            [PackageMetadataLookupMode::LOCAL_CACHE_ONLY],
            [PackageMetadataLookupMode::PROJECT_REPOSITORIES],
        ];
    }

    public function testNetworkFailureWinsOverNotFoundWording(): void
    {
        $lookup = $this->lookupReturning(
            1,
            '',
            'Could not resolve host repo.example.test. Package vendor/package not found.'
        );

        $result = $this->lookup($lookup, '^2.0');

        self::assertSame(PackageMetadataLookupResult::STATUS_UNVERIFIED, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_PROCESS_FAILURE, $result->reason());
    }

    /**
     * @dataProvider malformedOutputProvider
     */
    public function testMalformedComposerOutputIsUnverified(string $output): void
    {
        $result = $this->lookup($this->lookupReturning(0, $output), '^2.0');

        self::assertSame(PackageMetadataLookupResult::STATUS_UNVERIFIED, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_MALFORMED_OUTPUT, $result->reason());
    }

    public function testInvalidProjectIsUnverifiedWithoutStartingComposer(): void
    {
        $calls = 0;
        $lookup = new ComposerPackageMetadataLookup(
            static function () use (&$calls): array {
                ++$calls;

                return ['exit_code' => 0, 'stdout' => '{}', 'stderr' => ''];
            }
        );

        $result = $lookup->lookup(
            __DIR__ . DIRECTORY_SEPARATOR . 'missing-project',
            'vendor/package',
            '^2.0',
            ComposerExecutionConfiguration::compatible(),
            PackageMetadataLookupMode::PROJECT_REPOSITORIES
        );

        self::assertSame(PackageMetadataLookupResult::STATUS_UNVERIFIED, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_INVALID_PROJECT, $result->reason());
        self::assertSame(0, $calls);
    }

    public function testOversizedComposerJsonOutputIsUnverifiedBeforeDecoding(): void
    {
        $lookup = $this->lookupReturning(0, str_repeat('x', 2_000_001), 'safe diagnostic');

        $result = $this->lookup($lookup, '^2.0');

        self::assertSame(PackageMetadataLookupResult::STATUS_UNVERIFIED, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_OUTPUT_TOO_LARGE, $result->reason());
        self::assertSame('safe diagnostic', $result->diagnostic());
    }

    /** @return list<array{string}> */
    public function malformedOutputProvider(): array
    {
        return [
            ['not json'],
            [json_encode(['name' => 'vendor/package'], JSON_THROW_ON_ERROR)],
            [json_encode(['name' => 'another/package', 'versions' => ['2.0.0']], JSON_THROW_ON_ERROR)],
            [json_encode(['name' => 'vendor/package', 'versions' => ['2.0.0', ['bad']]], JSON_THROW_ON_ERROR)],
        ];
    }

    public function testTimeoutIsOperationallyUnverified(): void
    {
        $process = new Process(['composer', 'show']);
        $process->setTimeout(10);
        $lookup = new ComposerPackageMetadataLookup(
            static function () use ($process): array {
                throw new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
            }
        );

        $result = $this->lookup($lookup, '^2.0');

        self::assertSame(PackageMetadataLookupResult::STATUS_UNVERIFIED, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_PROCESS_TIMEOUT, $result->reason());
    }

    public function testUnexpectedRunnerFailureIsOperationallyUnverified(): void
    {
        $lookup = new ComposerPackageMetadataLookup(
            static function (): array {
                throw new \RuntimeException('runner unavailable');
            }
        );

        $result = $this->lookup($lookup, '^2.0');

        self::assertSame(PackageMetadataLookupResult::STATUS_UNVERIFIED, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_PROCESS_FAILURE, $result->reason());
        self::assertSame('runner unavailable', $result->diagnostic());
    }

    public function testDefaultSymfonyProcessRunnerReturnsAClassifiedProcessResult(): void
    {
        $execution = new ComposerExecutionConfiguration(PHP_BINARY, '*', 300, 5);

        $result = (new ComposerPackageMetadataLookup())->lookup(
            $this->projectPath(),
            'vendor/package',
            '^2.0',
            $execution,
            PackageMetadataLookupMode::PROJECT_REPOSITORIES
        );

        self::assertSame(PackageMetadataLookupResult::STATUS_UNVERIFIED, $result->status());
        self::assertSame(PackageMetadataLookupResult::REASON_PROCESS_FAILURE, $result->reason());
        self::assertNotSame('', $result->diagnostic());
    }

    public function testMalformedPackageAndConstraintDoNotStartComposer(): void
    {
        $calls = 0;
        $lookup = new ComposerPackageMetadataLookup(
            static function () use (&$calls): array {
                ++$calls;

                return ['exit_code' => 0, 'stdout' => '{}', 'stderr' => ''];
            }
        );

        $invalidPackage = $lookup->lookup(
            $this->projectPath(),
            'not-a-package',
            '^2.0',
            ComposerExecutionConfiguration::compatible(),
            PackageMetadataLookupMode::PROJECT_REPOSITORIES
        );
        $invalidConstraint = $lookup->lookup(
            $this->projectPath(),
            'vendor/package',
            'not a constraint',
            ComposerExecutionConfiguration::compatible(),
            PackageMetadataLookupMode::PROJECT_REPOSITORIES
        );
        $emptyConstraint = $lookup->lookup(
            $this->projectPath(),
            'vendor/package',
            '',
            ComposerExecutionConfiguration::compatible(),
            PackageMetadataLookupMode::PROJECT_REPOSITORIES
        );

        self::assertSame(PackageMetadataLookupResult::STATUS_INVALID, $invalidPackage->status());
        self::assertSame(PackageMetadataLookupResult::REASON_INVALID_PACKAGE, $invalidPackage->reason());
        self::assertSame(PackageMetadataLookupResult::STATUS_INVALID, $invalidConstraint->status());
        self::assertSame(PackageMetadataLookupResult::REASON_INVALID_CONSTRAINT, $invalidConstraint->reason());
        self::assertSame(PackageMetadataLookupResult::STATUS_INVALID, $emptyConstraint->status());
        self::assertSame(PackageMetadataLookupResult::REASON_INVALID_CONSTRAINT, $emptyConstraint->reason());
        self::assertSame(0, $calls);
    }

    public function testOperationalDiagnosticsAreBoundedAndSensitiveValuesAreRedacted(): void
    {
        $secret = 'ghp_0123456789abcdefghijklmnop';
        $lookup = $this->lookupReturning(
            1,
            '',
            'Authorization: Bearer ' . $secret . PHP_EOL
            . 'https://user:password@repo.example.test/packages.json ' . str_repeat('x', 6000)
        );

        $result = $this->lookup($lookup, '^2.0');

        self::assertSame(PackageMetadataLookupResult::STATUS_UNVERIFIED, $result->status());
        self::assertStringNotContainsString($secret, $result->diagnostic());
        self::assertStringNotContainsString('user:password', $result->diagnostic());
        self::assertStringContainsString('[REDACTED', $result->diagnostic());
        self::assertLessThanOrEqual(4000, strlen($result->diagnostic()));
    }

    public function testLookupModeMustBeExplicitlySupported(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ComposerPackageMetadataLookup())->lookup(
            $this->projectPath(),
            'vendor/package',
            '^2.0',
            ComposerExecutionConfiguration::compatible(),
            'automatic'
        );
    }

    public function testProjectPathCanonicalizationPreservesTheFilesystemRoot(): void
    {
        $path = realpath($this->projectPath());
        self::assertNotFalse($path);
        while (dirname($path) !== $path) {
            $path = dirname($path);
        }

        $method = new \ReflectionMethod(ComposerPackageMetadataLookup::class, 'canonicalProjectPath');
        $method->setAccessible(true);

        self::assertSame($path, $method->invoke(new ComposerPackageMetadataLookup(), $path));
    }

    private function lookupReturning(int $exitCode, string $stdout, string $stderr = ''): ComposerPackageMetadataLookup
    {
        return new ComposerPackageMetadataLookup(
            static fn (): array => [
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ]
        );
    }

    private function lookup(ComposerPackageMetadataLookup $lookup, string $constraint): PackageMetadataLookupResult
    {
        return $lookup->lookup(
            $this->projectPath(),
            'vendor/package',
            $constraint,
            ComposerExecutionConfiguration::compatible(),
            PackageMetadataLookupMode::PROJECT_REPOSITORIES
        );
    }

    private function projectPath(): string
    {
        return dirname(__DIR__, 5);
    }
}
