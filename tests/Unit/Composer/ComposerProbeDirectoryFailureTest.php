<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer {
    function random_bytes(int $length): string
    {
        $forcedBytes = $GLOBALS['php_upgrade_preflight_forced_probe_bytes'] ?? null;
        if (is_string($forcedBytes) && strlen($forcedBytes) === $length) {
            return $forcedBytes;
        }

        return \random_bytes($length);
    }
}

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer {
    use PhpUpgradePreflight\Core\Composer\ComposerScenarioRunner;
    use PHPUnit\Framework\TestCase;

    final class ComposerProbeDirectoryFailureTest extends TestCase
    {
        public function testProbeDirectoryCreationFailsClosedAfterRepeatedCollisions(): void
        {
            $collisionPath = null;
            $collisionBytes = null;
            for ($attempt = 0; $attempt < 10; ++$attempt) {
                $candidateBytes = \random_bytes(8);
                $candidatePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR
                    . 'php-upgrade-preflight-composer-probe-'
                    . bin2hex($candidateBytes);
                if (@mkdir($candidatePath, 0700)) {
                    $collisionPath = $candidatePath;
                    $collisionBytes = $candidateBytes;
                    break;
                }
            }

            self::assertIsString($collisionPath);
            self::assertIsString($collisionBytes);
            $method = new \ReflectionMethod(ComposerScenarioRunner::class, 'createComposerProbeDirectory');
            $method->setAccessible(true);
            $GLOBALS['php_upgrade_preflight_forced_probe_bytes'] = $collisionBytes;

            try {
                $method->invoke(new ComposerScenarioRunner());
                self::fail('Expected repeated probe-directory collisions to fail closed.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                self::assertInstanceOf(\RuntimeException::class, $exception);
                self::assertSame(
                    'Unable to create an isolated Composer platform probe directory.',
                    $exception->getMessage()
                );
            } finally {
                unset($GLOBALS['php_upgrade_preflight_forced_probe_bytes']);
                @rmdir($collisionPath);
            }
        }
    }
}
