<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Model;

use PhpUpgradePreflight\Core\Model\CandidateLockEvidence;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PHPUnit\Framework\TestCase;

final class CandidateLockEvidenceTest extends TestCase
{
    public function testFileFingerprintIsStableAcrossLineEndings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'preflight-lock-');
        self::assertIsString($path);

        $lfContents = "{\n    \"content-hash\": \"portable\",\n    \"packages\": []\n}\n";
        $crlfContents = str_replace("\n", "\r\n", $lfContents);

        try {
            $lock = new ComposerLock(['content-hash' => 'portable', 'packages' => []]);
            self::assertNotFalse(file_put_contents($path, $lfContents));
            $lfEvidence = CandidateLockEvidence::fromFile($path, $lock);
            self::assertNotFalse(file_put_contents($path, $crlfContents));
            $crlfEvidence = CandidateLockEvidence::fromFile($path, $lock);

            self::assertSame(hash('sha256', $lfContents), $lfEvidence->sha256());
            self::assertSame($lfEvidence->toArray(), $crlfEvidence->toArray());
        } finally {
            @unlink($path);
        }
    }
}
