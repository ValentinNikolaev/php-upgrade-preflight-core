<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Support;

use PhpUpgradePreflight\Core\Support\OutputExcerpt;
use PhpUpgradePreflight\Core\Support\SensitiveOutputRedactor;
use PHPUnit\Framework\TestCase;

final class OutputExcerptTest extends TestCase
{
    public function testShortOutputIsReturnedWithoutATruncationMarker(): void
    {
        $excerpt = OutputExcerpt::bounded('Nothing to hide here.');

        self::assertSame('Nothing to hide here.', $excerpt);
        self::assertStringNotContainsString('[TRUNCATED', $excerpt);
    }

    public function testTruncatedOutputNamesTheOriginalSizeAndStaysWithinTheBudget(): void
    {
        $output = str_repeat('solver-line-', 2000);
        $excerpt = OutputExcerpt::bounded($output, 200);

        self::assertLessThanOrEqual(200, strlen($excerpt));
        self::assertStringContainsString(
            sprintf('[TRUNCATED: %d bytes of output omitted]', strlen($output)),
            $excerpt
        );
        self::assertStringStartsWith('solver-line-', $excerpt);
    }

    public function testABudgetTooSmallForTheMarkerStillTruncatesWithoutOverflowing(): void
    {
        $excerpt = OutputExcerpt::bounded(str_repeat('x', 500), 10);

        self::assertSame(str_repeat('x', 10), $excerpt);
    }

    public function testTruncationIsReportedEvenWhenRedactionShrinksOutputBelowTheBudget(): void
    {
        $output = str_repeat('a', 20000) . 'Authorization: Bearer ' . str_repeat('b', 40);
        $excerpt = OutputExcerpt::bounded($output, 4000);

        self::assertStringContainsString('[TRUNCATED: ' . strlen($output) . ' bytes of output omitted]', $excerpt);
    }

    public function testACredentialCrossingTheExcerptBoundaryIsStillRedacted(): void
    {
        $token = 'ghp_' . str_repeat('A', 36);
        $output = str_repeat('.', 3990) . 'Authorization: Bearer ' . $token . str_repeat('.', 100);

        $excerpt = OutputExcerpt::bounded($output);

        self::assertStringNotContainsString($token, $excerpt);
    }

    public function testMegabyteOutputIsBoundedBeforeRedactionRunsOverIt(): void
    {
        $token = 'ghp_' . str_repeat('B', 36);
        $output = 'composer output ' . $token . ' ' . str_repeat('noise ', 500000);
        self::assertGreaterThan(3000000, strlen($output));

        $excerpt = OutputExcerpt::bounded($output);

        self::assertStringStartsWith('composer output ', $excerpt);
        self::assertStringNotContainsString($token, $excerpt);
        self::assertNotSame(SensitiveOutputRedactor::REDACTION_FAILED, $excerpt);
        self::assertLessThanOrEqual(4000, strlen($excerpt));
    }

    public function testACutLandingInsideAMultibyteCharacterDropsThatCharacter(): void
    {
        $multibyte = "\u{00e9}";
        self::assertSame(2, strlen($multibyte));

        $excerpt = OutputExcerpt::bounded(str_repeat('a', 10) . $multibyte . str_repeat('b', 100), 11);

        self::assertSame(str_repeat('a', 10), $excerpt);
        self::assertSame(1, preg_match('//u', $excerpt));
    }

    public function testNegativeBudgetsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OutputExcerpt::bounded('value', -1);
    }
}
