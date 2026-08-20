<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\PackageRef;
use PhpUpgradePreflight\Core\Support\OutputExcerpt;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Read-only Composer package discovery for interactive target selection.
 *
 * The caller must choose a lookup mode explicitly. Local-cache lookup never
 * claims that an absent package does not exist, while project-repository lookup
 * may return `not_found` only for Composer's explicit package-not-found result.
 */
final class ComposerPackageMetadataLookup
{
    private const MAX_JSON_BYTES = 2_000_000;
    private const MAX_PACKAGE_NAME_BYTES = 255;
    private const MAX_CONSTRAINT_BYTES = 1024;
    private const MAX_RETAINED_VERSIONS = 100;
    private const MAX_RETAINED_MATCHING_VERSIONS = 100;

    /** @var \Closure(list<string>, string, array<string, string|false>, int): array{exit_code: int, stdout: string, stderr: string} */
    private \Closure $processRunner;

    /**
     * @param null|callable(list<string>, string, array<string, string|false>, int): array{exit_code: int, stdout: string, stderr: string} $processRunner
     */
    public function __construct(?callable $processRunner = null)
    {
        $this->processRunner = $processRunner === null
            ? \Closure::fromCallable([$this, 'runProcess'])
            : \Closure::fromCallable($processRunner);
    }

    public function lookup(
        string $projectPath,
        string $package,
        string $constraint,
        ComposerExecutionConfiguration $execution,
        string $mode
    ): PackageMetadataLookupResult {
        PackageMetadataLookupMode::assertSupported($mode);

        $package = strtolower(trim($package));
        $constraint = trim($constraint);
        if (strlen($package) > self::MAX_PACKAGE_NAME_BYTES || !PackageRef::isValidName($package)) {
            return $this->result(
                PackageMetadataLookupResult::STATUS_INVALID,
                PackageMetadataLookupResult::REASON_INVALID_PACKAGE,
                $package,
                $constraint
            );
        }
        if (strlen($constraint) > self::MAX_CONSTRAINT_BYTES || !$this->isValidConstraint($constraint)) {
            return $this->result(
                PackageMetadataLookupResult::STATUS_INVALID,
                PackageMetadataLookupResult::REASON_INVALID_CONSTRAINT,
                $package,
                $constraint
            );
        }
        if ($execution->isRestricted()) {
            return $this->result(
                PackageMetadataLookupResult::STATUS_UNVERIFIED,
                PackageMetadataLookupResult::REASON_RESTRICTED_EXECUTION_UNAVAILABLE,
                $package,
                $constraint,
                [],
                [],
                0,
                0,
                'Restricted package metadata lookup requires isolated Composer state and is not available.'
            );
        }

        $projectPath = $this->canonicalProjectPath($projectPath);
        if ($projectPath === '' || !is_dir($projectPath) || !is_file($projectPath . DIRECTORY_SEPARATOR . 'composer.json')) {
            return $this->result(
                PackageMetadataLookupResult::STATUS_UNVERIFIED,
                PackageMetadataLookupResult::REASON_INVALID_PROJECT,
                $package,
                $constraint,
                [],
                [],
                0,
                0,
                'Package metadata lookup requires a readable project directory containing composer.json.'
            );
        }

        $command = [
            $execution->executable(),
            'show',
            $package,
            '--all',
            '--format=json',
            '--no-interaction',
            '--no-plugins',
            '--no-scripts',
            '--no-ansi',
        ];
        $environment = [
            // Always select composer.json from the supplied project directory;
            // an inherited COMPOSER path would silently change the repository universe.
            'COMPOSER' => false,
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_NO_AUDIT' => '1',
            'COMPOSER_DISABLE_NETWORK' => PackageMetadataLookupMode::allowsNetwork($mode) ? false : '1',
        ];

        try {
            $process = ($this->processRunner)(
                $command,
                $projectPath,
                $environment,
                $execution->diagnosticTimeoutSeconds()
            );
        } catch (\Throwable $exception) {
            return $this->exceptionResult($package, $constraint, $exception);
        }

        $stdout = $process['stdout'];
        $stderr = $process['stderr'];
        if ($process['exit_code'] !== 0) {
            return $this->failedProcessResult($package, $constraint, $mode, $stdout, $stderr);
        }
        if (strlen($stdout) > self::MAX_JSON_BYTES) {
            return $this->result(
                PackageMetadataLookupResult::STATUS_UNVERIFIED,
                PackageMetadataLookupResult::REASON_OUTPUT_TOO_LARGE,
                $package,
                $constraint,
                [],
                [],
                0,
                0,
                $stderr
            );
        }

        try {
            $metadata = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return $this->result(
                PackageMetadataLookupResult::STATUS_UNVERIFIED,
                PackageMetadataLookupResult::REASON_MALFORMED_OUTPUT,
                $package,
                $constraint,
                [],
                [],
                0,
                0,
                trim($stderr . PHP_EOL . $exception->getMessage())
            );
        }

        if (!is_array($metadata)
            || !isset($metadata['name'], $metadata['versions'])
            || !is_string($metadata['name'])
            || strtolower($metadata['name']) !== $package
            || !is_array($metadata['versions'])) {
            return $this->result(
                PackageMetadataLookupResult::STATUS_UNVERIFIED,
                PackageMetadataLookupResult::REASON_MALFORMED_OUTPUT,
                $package,
                $constraint,
                [],
                [],
                0,
                0,
                $stderr
            );
        }

        return $this->foundResult($package, $constraint, $metadata['versions'], $stderr);
    }

    private function canonicalProjectPath(string $projectPath): string
    {
        $resolved = realpath($projectPath);

        return $resolved === false ? $projectPath : $resolved;
    }

    private function isValidConstraint(string $constraint): bool
    {
        if ($constraint === '') {
            return false;
        }

        try {
            (new VersionParser())->parseConstraints($constraint);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<array-key, mixed> $rawVersions
     */
    private function foundResult(
        string $package,
        string $constraint,
        array $rawVersions,
        string $diagnostic
    ): PackageMetadataLookupResult {
        $versions = [];
        $matchingVersions = [];
        $availableVersionCount = 0;
        $matchingVersionCount = 0;
        $seen = [];

        foreach ($rawVersions as $rawVersion) {
            if (!is_string($rawVersion)) {
                return $this->result(
                    PackageMetadataLookupResult::STATUS_UNVERIFIED,
                    PackageMetadataLookupResult::REASON_MALFORMED_OUTPUT,
                    $package,
                    $constraint,
                    [],
                    [],
                    0,
                    0,
                    $diagnostic
                );
            }

            $version = preg_replace('/^\*\s*/', '', trim($rawVersion));
            if ($version === null || $version === '' || isset($seen[$version])) {
                continue;
            }
            $seen[$version] = true;
            ++$availableVersionCount;
            if (count($versions) < self::MAX_RETAINED_VERSIONS) {
                $versions[] = $version;
            }

            try {
                $matches = Semver::satisfies($version, $constraint);
            } catch (\Throwable) {
                $matches = false;
            }
            if (!$matches) {
                continue;
            }

            ++$matchingVersionCount;
            if (count($matchingVersions) < self::MAX_RETAINED_MATCHING_VERSIONS) {
                $matchingVersions[] = $version;
            }
        }

        return $this->result(
            PackageMetadataLookupResult::STATUS_FOUND,
            PackageMetadataLookupResult::REASON_PACKAGE_FOUND,
            $package,
            $constraint,
            $versions,
            $matchingVersions,
            $availableVersionCount,
            $matchingVersionCount,
            $diagnostic
        );
    }

    private function failedProcessResult(
        string $package,
        string $constraint,
        string $mode,
        string $stdout,
        string $stderr
    ): PackageMetadataLookupResult {
        $diagnostic = trim($stderr . PHP_EOL . $stdout);
        if ($mode === PackageMetadataLookupMode::LOCAL_CACHE_ONLY) {
            return $this->result(
                PackageMetadataLookupResult::STATUS_UNVERIFIED,
                PackageMetadataLookupResult::REASON_LOCAL_METADATA_UNAVAILABLE,
                $package,
                $constraint,
                [],
                [],
                0,
                0,
                $diagnostic
            );
        }

        if (!$this->looksOperationallyUnavailable($diagnostic) && $this->isExplicitNotFound($diagnostic, $package)) {
            return $this->result(
                PackageMetadataLookupResult::STATUS_NOT_FOUND,
                PackageMetadataLookupResult::REASON_PACKAGE_NOT_FOUND,
                $package,
                $constraint,
                [],
                [],
                0,
                0,
                $diagnostic
            );
        }

        return $this->result(
            PackageMetadataLookupResult::STATUS_UNVERIFIED,
            PackageMetadataLookupResult::REASON_PROCESS_FAILURE,
            $package,
            $constraint,
            [],
            [],
            0,
            0,
            $diagnostic
        );
    }

    private function isExplicitNotFound(string $diagnostic, string $package): bool
    {
        return preg_match(
            '/\bPackage\s+["\']?' . preg_quote($package, '/') . '["\']?\s+(?:was\s+)?not found\b/i',
            $diagnostic
        ) === 1;
    }

    private function looksOperationallyUnavailable(string $diagnostic): bool
    {
        return preg_match(
            '/(?:network (?:is )?disabled|offline|could not resolve host|name or service not known|'
            . 'temporary failure in name resolution|connection (?:timed out|refused)|curl error|'
            . 'failed to (?:open stream|download)|could not authenticate|authentication required|'
            . 'transport exception|proxy error|ssl (?:error|certificate problem))/i',
            $diagnostic
        ) === 1;
    }

    private function exceptionResult(
        string $package,
        string $constraint,
        \Throwable $exception
    ): PackageMetadataLookupResult {
        $reason = $exception instanceof ProcessTimedOutException
            ? PackageMetadataLookupResult::REASON_PROCESS_TIMEOUT
            : PackageMetadataLookupResult::REASON_PROCESS_FAILURE;
        $diagnostic = $exception->getMessage();
        if ($exception instanceof ProcessTimedOutException) {
            try {
                $process = $exception->getProcess();
                $diagnostic = trim($process->getErrorOutput() . PHP_EOL . $process->getOutput() . PHP_EOL . $diagnostic);
            } catch (\Throwable) {
                // The exception message remains useful and is redacted below.
            }
        }

        return $this->result(
            PackageMetadataLookupResult::STATUS_UNVERIFIED,
            $reason,
            $package,
            $constraint,
            [],
            [],
            0,
            0,
            $diagnostic
        );
    }

    /**
     * @param list<string> $versions
     * @param list<string> $matchingVersions
     */
    private function result(
        string $status,
        string $reason,
        string $package,
        string $constraint,
        array $versions = [],
        array $matchingVersions = [],
        int $availableVersionCount = 0,
        int $matchingVersionCount = 0,
        string $diagnostic = ''
    ): PackageMetadataLookupResult {
        return new PackageMetadataLookupResult(
            $status,
            $reason,
            $package,
            $constraint,
            $versions,
            $matchingVersions,
            $availableVersionCount,
            $matchingVersionCount,
            OutputExcerpt::bounded($diagnostic)
        );
    }

    /**
     * @param list<string> $command
     * @param array<string, string|false> $environment
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, string $directory, array $environment, int $timeoutSeconds): array
    {
        $process = new Process($command, $directory, $environment, null, $timeoutSeconds);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
