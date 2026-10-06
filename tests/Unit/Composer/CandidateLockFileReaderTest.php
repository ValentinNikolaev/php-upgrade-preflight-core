<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\CandidateLockFileReader;
use PhpUpgradePreflight\Core\Model\CandidateLockEvidence;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PHPUnit\Framework\TestCase;

final class CandidateLockFileReaderTest extends TestCase
{
    public function testItFingerprintsLockfileBytesIndependentlyOfLineEndings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'preflight-lock-');
        self::assertIsString($path);

        $lfContents = "{\n    \"content-hash\": \"portable\",\n    \"packages\": []\n}\n";
        $crlfContents = str_replace("\n", "\r\n", $lfContents);
        $reader = new CandidateLockFileReader();

        try {
            $lock = new ComposerLock(['content-hash' => 'portable', 'packages' => []]);
            self::assertNotFalse(file_put_contents($path, $lfContents));
            $lfEvidence = $reader->read($path, $lock);
            self::assertNotFalse(file_put_contents($path, $crlfContents));
            $crlfEvidence = $reader->read($path, $lock);

            self::assertSame(hash('sha256', $lfContents), $lfEvidence->sha256());
            self::assertSame($lfEvidence->toArray(), $crlfEvidence->toArray());
        } finally {
            @unlink($path);
        }
    }

    public function testItRejectsAMissingLockfileWithoutExposingThePath(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-lock-' . bin2hex(random_bytes(8)) . '.json';

        try {
            (new CandidateLockFileReader())->read($path, new ComposerLock([]));
            self::fail('Expected a missing candidate lockfile to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to fingerprint the candidate Composer lockfile.', $exception->getMessage());
            self::assertStringNotContainsString($path, $exception->getMessage());
        }
    }

    public function testEvidenceCanBeBuiltWithoutTouchingTheFilesystem(): void
    {
        $evidence = CandidateLockEvidence::fromLock(
            new ComposerLock(['content-hash' => 'portable', 'packages' => [
                ['name' => 'vendor/package', 'version' => '1.0.0'],
            ]])
        );

        self::assertSame('portable', $evidence->contentHash());
        self::assertSame(1, $evidence->packageCount());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence->sha256());
    }
}
