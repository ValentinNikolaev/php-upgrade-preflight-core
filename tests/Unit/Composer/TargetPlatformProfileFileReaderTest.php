<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Composer;

use PhpUpgradePreflight\Core\Composer\TargetPlatformProfileFileReader;
use PhpUpgradePreflight\Core\Model\TargetPlatformProfile;
use PHPUnit\Framework\TestCase;

final class TargetPlatformProfileFileReaderTest extends TestCase
{
    public function testItReadsAProfileFileAsAFileProvenanceProfile(): void
    {
        $path = dirname(__DIR__, 5) . '/tests/fixtures/platform-profiles/partial-php-83-ext-json.json';

        $profile = (new TargetPlatformProfileFileReader())->read($path);

        self::assertSame(TargetPlatformProfile::PROVENANCE_FILE, $profile->provenance());
        self::assertSame(TargetPlatformProfile::COMPLETENESS_PARTIAL, $profile->completeness());
        self::assertSame(
            TargetPlatformProfile::fromJson(
                (string) file_get_contents($path),
                TargetPlatformProfile::PROVENANCE_FILE
            )->digest(),
            $profile->digest()
        );
    }

    public function testItRejectsAMissingProfileWithoutExposingThePath(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-profile-' . bin2hex(random_bytes(8)) . '.json';

        try {
            (new TargetPlatformProfileFileReader())->read($path);
            self::fail('Expected a missing profile file to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Target platform profile file could not be read.', $exception->getMessage());
            self::assertStringNotContainsString($path, $exception->getMessage());
        }
    }

    public function testItRejectsAMalformedProfileFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid JSON');

        (new TargetPlatformProfileFileReader())
            ->read(dirname(__DIR__, 5) . '/tests/fixtures/platform-profiles/malformed.json');
    }
}
