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
        foreach ($fixture['safe_values'] as $safeValue) {
            self::assertStringContainsString($safeValue, $redacted);
        }
        self::assertSame($redacted, SensitiveOutputRedactor::redact($redacted));
    }

    public function testItRedactsCredentialBearingAndEscapedUrlsWhilePreservingOrdinaryUrls(): void
    {
        $fixture = $this->fixture();
        $output = implode("\n", [
            $fixture['safe_values']['ordinary_https_url'],
            $fixture['safe_values']['ordinary_escaped_url'],
            'https://' . $fixture['canaries']['url_username'] . ':' . $fixture['canaries']['url_password']
                . '@private-repo.example.invalid/acme/package.git',
            'https:\/\/' . $fixture['canaries']['escaped_url_username'] . ':' . $fixture['canaries']['escaped_url_password']
                . '@private-repo.example.invalid\/acme\/package.git',
            'https://private-repo.example.invalid/package.zip?access_token=' . $fixture['canaries']['query_token'],
        ]);

        $redacted = SensitiveOutputRedactor::redact($output);

        $this->assertNoCanaries($fixture['canaries'], $redacted, 'redacted URL output');
        self::assertStringContainsString($fixture['safe_values']['ordinary_https_url'], $redacted);
        self::assertStringContainsString($fixture['safe_values']['ordinary_escaped_url'], $redacted);
        self::assertSame(3, substr_count($redacted, SensitiveOutputRedactor::REDACTED_URL));
    }

    public function testItRedactsUserInformationAcrossUrlSchemesAndEscapeLayers(): void
    {
        $secrets = [
            'ftp-user',
            'ftp-password',
            'ftps-user',
            'ftps-password',
            'sftp-user',
            'sftp-password',
            'file-user',
            'file-password',
            'relative-user',
            'relative-password',
            'double-user',
            'double-password',
        ];
        $safe = '//public-repo.example.invalid/acme/package.zip';
        $output = implode("\n", [
            'ftp://ftp-user:ftp-password@private-repo.example.invalid/package.zip',
            'ftps://ftps-user:ftps-password@private-repo.example.invalid/package.zip',
            'sftp://sftp-user:sftp-password@private-repo.example.invalid/package.zip',
            'file://file-user:file-password@private-repo.example.invalid/package.zip',
            '//relative-user:relative-password@private-repo.example.invalid/package.zip',
            'https:\\\\/\\\\/double-user:double-password@private-repo.example.invalid\\\\/package.zip',
            $safe,
        ]);

        $redacted = SensitiveOutputRedactor::redact($output);
        $canaries = array_combine($secrets, $secrets);

        $this->assertNoCanaries($canaries, $redacted, 'scheme-complete URL output');
        self::assertSame(6, substr_count($redacted, SensitiveOutputRedactor::REDACTED_URL));
        self::assertStringContainsString($safe, $redacted);
    }

    public function testItRecursivelyRedactsStructuredCredentialValuesAndTokenBearingText(): void
    {
        $fixture = $this->fixture();
        $structured = [
            'repository_url' => $fixture['safe_values']['ordinary_https_url'],
            'authorization' => $fixture['canaries']['quoted_authorization'],
            'accessToken' => 'opaque-access-value',
            'clientSecret' => 'opaque-client-value',
            'auth' => [
                'bearer' => [
                    'private-repo.example.invalid' => $fixture['canaries']['composer_bearer_token'],
                ],
            ],
            'message' => 'Composer failed with ' . $fixture['canaries']['npm_token'],
            'count' => 2,
            'nullable' => null,
            'object' => (object) [
                'AWS_SECRET_ACCESS_KEY' => $fixture['canaries']['aws_secret_key'],
                'safe' => 'ordinary value',
            ],
            'custom_object' => new class ($fixture['canaries']['composer_bearer_token']) implements \JsonSerializable {
                private string $authorization;

                public function __construct(string $authorization)
                {
                    $this->authorization = $authorization;
                }

                /** @return array{authorization: string, safe: string} */
                public function jsonSerialize(): array
                {
                    return [
                        'authorization' => $this->authorization,
                        'safe' => 'serialized value',
                    ];
                }
            },
        ];

        $redacted = SensitiveOutputRedactor::redactStructured($structured);
        self::assertIsArray($redacted);
        $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);

        $this->assertNoCanaries($fixture['canaries'], $encoded, 'structured redaction output');
        self::assertSame($fixture['safe_values']['ordinary_https_url'], $redacted['repository_url']);
        self::assertSame(SensitiveOutputRedactor::REDACTED, $redacted['authorization']);
        self::assertSame(SensitiveOutputRedactor::REDACTED, $redacted['accessToken']);
        self::assertSame(SensitiveOutputRedactor::REDACTED, $redacted['clientSecret']);
        self::assertSame(
            SensitiveOutputRedactor::REDACTED,
            $redacted['auth']['bearer']['private-repo.example.invalid']
        );
        self::assertSame('Composer failed with ' . SensitiveOutputRedactor::REDACTED_TOKEN, $redacted['message']);
        self::assertSame(2, $redacted['count']);
        self::assertNull($redacted['nullable']);
        self::assertInstanceOf(\stdClass::class, $redacted['object']);
        self::assertSame(SensitiveOutputRedactor::REDACTED, $redacted['object']->AWS_SECRET_ACCESS_KEY);
        self::assertSame('ordinary value', $redacted['object']->safe);
        self::assertSame(SensitiveOutputRedactor::REDACTED, $redacted['custom_object']['authorization']);
        self::assertSame('serialized value', $redacted['custom_object']['safe']);
        self::assertEquals($redacted, SensitiveOutputRedactor::redactStructured($redacted));
    }

    public function testItRedactsAdditionalProviderTokensAndDelimitedCredentialValues(): void
    {
        $secrets = [
            'xapp-1-FixtureSlackApplicationToken0123456789',
            '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456789',
            'comma-prefix-fixture',
            'comma-suffix-fixture',
            'semicolon-prefix-fixture',
            'semicolon-suffix-fixture',
        ];
        $output = implode("\n", [
            'Slack application token ' . $secrets[0],
            'Google OAuth secret ' . $secrets[1],
            'password=' . $secrets[2] . ',' . $secrets[3],
            'token=' . $secrets[4] . ';' . $secrets[5],
        ]);

        $redacted = SensitiveOutputRedactor::redact($output);
        $canaries = array_combine($secrets, $secrets);

        $this->assertNoCanaries($canaries, $redacted, 'provider and delimited output');
        self::assertStringContainsString(SensitiveOutputRedactor::REDACTED_TOKEN, $redacted);
        self::assertStringContainsString('password=' . SensitiveOutputRedactor::REDACTED, $redacted);
        self::assertStringContainsString('token=' . SensitiveOutputRedactor::REDACTED, $redacted);
    }

    public function testItRedactsStripeTokenPatternsWithoutPersistingASecretShapedFixture(): void
    {
        $token = 'sk_' . 'live_' . 'FixtureStandalone0123456789';
        $redacted = SensitiveOutputRedactor::redact('Standalone Stripe token ' . $token);

        self::assertStringNotContainsString($token, $redacted);
        self::assertSame('Standalone Stripe token ' . SensitiveOutputRedactor::REDACTED_TOKEN, $redacted);
    }

    public function testItRedactsAuthorizationInsideEscapedJsonText(): void
    {
        foreach ([0, 1, 2, 4] as $depth) {
            $slashes = str_repeat('\\', $depth);
            foreach (['Authorization', 'COMPOSER_AUTH'] as $field) {
                $secret = sprintf('opaque-escaped-%s-secret-%d', strtolower($field), $depth);
                $input = '{' . $slashes . '"' . $field . $slashes . '":'
                    . $slashes . '"' . $secret . $slashes . '"}';
                $redacted = SensitiveOutputRedactor::redact($input);

                self::assertStringNotContainsString($secret, $redacted);
                self::assertStringContainsString(SensitiveOutputRedactor::REDACTED, $redacted);
                self::assertSame($redacted, SensitiveOutputRedactor::redact($redacted));
            }
        }
    }

    public function testItRedactsPhpStyleMultilineComposerAuthenticationMaps(): void
    {
        $username = 'opaque-multiline-user-value';
        $password = 'opaque-multiline-password-value';
        $token = 'opaque-multiline-bearer-value';
        $output = implode("\n", [
            '[COMPOSER_AUTH] => Array',
            '(',
            '  [http-basic] => Array',
            '  (',
            '    [private.example.invalid] => Array',
            '    (',
            '      [username] => ' . $username,
            '      [password] => ' . $password,
            '    )',
            '  )',
            '  [bearer] => Array',
            '  (',
            '    [private.example.invalid] => ' . $token,
            '  )',
            ')',
        ]);

        $redacted = SensitiveOutputRedactor::redact($output);

        self::assertStringNotContainsString($username, $redacted);
        self::assertStringNotContainsString($password, $redacted);
        self::assertStringNotContainsString($token, $redacted);
        self::assertStringContainsString('[COMPOSER_AUTH] => ' . SensitiveOutputRedactor::REDACTED, $redacted);
        self::assertSame($redacted, SensitiveOutputRedactor::redact($redacted));
    }

    public function testItRedactsBracketedCredentialFieldsWithoutAComposerAuthWrapper(): void
    {
        $output = "[username] => opaque-user\n[password] => opaque-password";
        $redacted = SensitiveOutputRedactor::redact($output);

        self::assertSame(
            '[username] => ' . SensitiveOutputRedactor::REDACTED
                . "\n[password] => " . SensitiveOutputRedactor::REDACTED,
            $redacted
        );
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

    public function testLowRegexBacktrackLimitsCannotDisableRedaction(): void
    {
        $previousLimit = ini_get('pcre.backtrack_limit');
        self::assertIsString($previousLimit);
        ini_set('pcre.backtrack_limit', '1');

        $secret = str_repeat('a', 500);

        try {
            $redacted = SensitiveOutputRedactor::redact('password="' . $secret . '"');
        } finally {
            ini_set('pcre.backtrack_limit', $previousLimit);
        }

        self::assertStringNotContainsString($secret, $redacted);
        self::assertStringContainsString(SensitiveOutputRedactor::REDACTED, $redacted);
    }

    /** @return array{canaries: array<string, string>, safe_values: array<string, string>, stdout: string, stderr: string} */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 5) . '/tests/fixtures/security/composer-output-with-secrets.json';
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);
        $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['canaries'] ?? null);
        self::assertIsArray($fixture['safe_values'] ?? null);
        self::assertIsString($fixture['stdout'] ?? null);
        self::assertIsString($fixture['stderr'] ?? null);

        /** @var array{canaries: array<string, string>, safe_values: array<string, string>, stdout: string, stderr: string} $fixture */
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
