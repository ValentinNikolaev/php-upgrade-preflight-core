<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Support;

use PhpUpgradePreflight\Core\Support\OutputExcerpt;
use PhpUpgradePreflight\Core\Support\SensitiveOutputRedactor;
use PHPUnit\Framework\TestCase;

final class SensitiveOutputRedactorTest extends TestCase
{
    public function testItRedactsCredentialBearingComposerOutputWithoutLosingSolverEvidence(): void
    {
        $fixture = $this->fixture();
        $output = $fixture['stdout'] . "\n" . $fixture['stderr'];
        $redacted = SensitiveOutputRedactor::redact($output);

        $this->assertNoCanaries($fixture['canaries'], $redacted, 'redacted Composer output');
        self::assertStringContainsString(SensitiveOutputRedactor::REDACTED, $redacted);
        self::assertStringContainsString(SensitiveOutputRedactor::REDACTED_URL, $redacted);
        self::assertStringContainsString(
            '- vendor/blocker 1.0.0 requires vendor/private-package (^1.0).',
            $redacted
        );
        self::assertSame($redacted, SensitiveOutputRedactor::redact($redacted));
    }

    public function testOutputIsRedactedBeforeItIsBounded(): void
    {
        $fixture = $this->fixture();
        $output = str_repeat('ordinary-output-', 300) . $fixture['stderr'];
        $excerpt = OutputExcerpt::bounded($output);

        self::assertLessThanOrEqual(4000, strlen($excerpt));
        $this->assertNoCanaries($fixture['canaries'], $excerpt, 'bounded output excerpt');
    }

    public function testMalformedUtf8CannotDisableRedaction(): void
    {
        $token = 'ghp_' . 'MalformedByteFixture' . '0123456789';
        $output = 'Authorization: Bearer ' . $token . chr(0xFF);
        $redacted = SensitiveOutputRedactor::redact($output);

        $this->assertNoCanaries(['malformed_utf8_token' => $token], $redacted, 'malformed-byte output');
        self::assertStringContainsString(SensitiveOutputRedactor::REDACTED, $redacted);
    }

    public function testRegexFailureClosesTheOutputBoundary(): void
    {
        $previousLimit = ini_get('pcre.backtrack_limit');
        self::assertIsString($previousLimit);
        ini_set('pcre.backtrack_limit', '1');

        try {
            $redacted = SensitiveOutputRedactor::redact('password="' . str_repeat('a', 500) . '"');
        } finally {
            ini_set('pcre.backtrack_limit', $previousLimit);
        }

        self::assertSame(SensitiveOutputRedactor::REDACTED, $redacted);
    }

    /** @return array{canaries: array<string, string>, stdout: string, stderr: string} */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 5) . '/tests/fixtures/security/composer-output-with-secrets.json';
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);
        $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['canaries'] ?? null);
        self::assertIsString($fixture['stdout'] ?? null);
        self::assertIsString($fixture['stderr'] ?? null);

        /** @var array{canaries: array<string, string>, stdout: string, stderr: string} $fixture */
        return $fixture;
    }

    /** @param array<string, string> $canaries */
    private function assertNoCanaries(array $canaries, string $surface, string $surfaceName): void
    {
        foreach ($canaries as $label => $canary) {
            if (str_contains($surface, $canary)) {
                self::fail(sprintf('Sensitive canary %s reached %s.', $label, $surfaceName));
            }
        }
    }
}
