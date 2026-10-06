<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Support;

use PhpUpgradePreflight\Core\Composer\JsonFileException;
use PhpUpgradePreflight\Core\Composer\JsonFileReader;
use PhpUpgradePreflight\Core\Filesystem\WorkspaceCleanupException;
use PhpUpgradePreflight\Core\Model\CandidateLockEvidence;
use PhpUpgradePreflight\Core\Support\PathExposurePolicy;
use PHPUnit\Framework\TestCase;

final class ExceptionPrivacyTest extends TestCase
{
    public function testExpectedFilesystemFailuresNeitherWarnNorExposeSensitivePaths(): void
    {
        $canary = 'npm_FixtureExceptionToken0123456789ABCDE';
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $canary . '.json';
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            try {
                (new JsonFileReader())->read($path);
                self::fail('The missing JSON file must fail.');
            } catch (JsonFileException $exception) {
                self::assertSame($path, $exception->path());
                self::assertStringNotContainsString($canary, $exception->getMessage());
            }

            try {
                CandidateLockEvidence::fromFile($path, new \PhpUpgradePreflight\Core\Model\ComposerLock([]));
                self::fail('The missing candidate lock must fail.');
            } catch (\RuntimeException $exception) {
                self::assertStringNotContainsString($canary, $exception->getMessage());
            }
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
    }

    public function testCleanupExceptionStringCannotExposeAnUnsafePreviousChain(): void
    {
        $canary = 'npm_FixturePreviousToken0123456789ABCDE';
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $canary;
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        $previousMaxLength = ini_get('zend.exception_string_param_max_len');
        self::assertIsString($previousIgnoreArgs);
        self::assertIsString($previousMaxLength);

        try {
            ini_set('zend.exception_ignore_args', '0');
            ini_set('zend.exception_string_param_max_len', '1000');
            $exception = new WorkspaceCleanupException(
                $workspace,
                'Cleanup failed in ' . $workspace . '.',
                new \RuntimeException('Native failure in ' . $workspace . '.')
            );
            $rendered = (string) $exception;
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            ini_set('zend.exception_string_param_max_len', $previousMaxLength);
        }

        self::assertNull($exception->getPrevious());
        self::assertSame($workspace, $exception->workspacePath());
        self::assertStringContainsString(PathExposurePolicy::ANALYZER_WORKSPACE, $rendered);
        self::assertStringNotContainsString($canary, $rendered);
        self::assertStringNotContainsString($workspace, $rendered);
    }

    public function testJsonFileExceptionStringCannotExposeTraceArguments(): void
    {
        $canary = 'npm_FixtureJsonPathToken0123456789ABCDE';
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $canary . DIRECTORY_SEPARATOR . 'composer.json';
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        $previousMaxLength = ini_get('zend.exception_string_param_max_len');
        self::assertIsString($previousIgnoreArgs);
        self::assertIsString($previousMaxLength);
        $rendered = '';
        $exceptionPath = null;

        try {
            ini_set('zend.exception_ignore_args', '0');
            ini_set('zend.exception_string_param_max_len', '1000');
            try {
                (new JsonFileReader())->read($path);
                self::fail('The missing JSON file must fail.');
            } catch (JsonFileException $exception) {
                $exceptionPath = $exception->path();
                $rendered = (string) $exception;
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            ini_set('zend.exception_string_param_max_len', $previousMaxLength);
        }

        self::assertSame($path, $exceptionPath);
        self::assertStringContainsString('Required Composer file "composer.json" was not found.', $rendered);
        self::assertStringNotContainsString($canary, $rendered);
        self::assertStringNotContainsString($path, $rendered);
    }
}
