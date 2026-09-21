<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Connection\Exception;

use Generator;
use Keboola\TableBackendUtils\Connection\Exception\OdbcErrorMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OdbcErrorMessageTest extends TestCase
{
    /**
     * @return Generator<string, array{string, string}>
     */
    public static function messageProvider(): Generator
    {
        yield 'ascii message is untouched' => [
            "Numeric value 'male' is not recognized",
            "Numeric value 'male' is not recognized",
        ];

        yield 'valid multibyte message is untouched' => [
            "Numeric value 'Příliš žluťoučký kůň 🐴' is not recognized",
            "Numeric value 'Příliš žluťoučký kůň 🐴' is not recognized",
        ];

        yield 'cut inside a two-byte character drops the dangling byte' => [
            "String 'Příliš žluťoučký k" . substr('ů', 0, 1),
            "String 'Příliš žluťoučký k",
        ];

        yield 'cut inside a three-byte character drops the dangling bytes' => [
            "String '" . substr('€', 0, 2),
            "String '",
        ];

        yield 'cut inside a four-byte character drops the dangling bytes' => [
            "String 'kůň " . substr('🐴', 0, 3),
            "String 'kůň ",
        ];

        yield 'invalid bytes inside the message are replaced, not dropped' => [
            "String 'a\xC3(b' is too long",
            "String 'a?(b' is too long",
        ];

        // A wholly-invalid short fragment (e.g. a lone multibyte lead byte) has no valid
        // remainder to keep, so it sanitizes down to an empty string. Callers that need a
        // non-empty message (see SnowflakeConnection's connection-failure fallback) must apply
        // their own fallback AFTER calling sanitize(), not before.
        yield 'wholly invalid short fragment sanitizes to an empty string' => [
            "\xC3",
            '',
        ];
    }

    #[DataProvider('messageProvider')]
    public function testSanitize(string $input, string $expected): void
    {
        $sanitized = OdbcErrorMessage::sanitize($input);

        $this->assertSame($expected, $sanitized);
        // the whole point: every consumer must be able to JSON-encode the message
        $this->assertJson(json_encode($sanitized, JSON_THROW_ON_ERROR));
    }

    public function testTruncatedMessageAtOdbcBufferSizeStaysReadable(): void
    {
        // PHP hands SQLError a 511-byte buffer; emulate a long Snowflake message cut there
        $value = str_repeat('ě', 300);
        $full = sprintf("Date '%s' is not recognized", $value);
        $cut = substr($full, 0, 511);
        // preg_match returns false (not 0) for a subject that is not valid UTF-8
        $this->assertFalse(preg_match('//u', $cut), 'fixture must be invalid UTF-8');

        $sanitized = OdbcErrorMessage::sanitize($cut);

        $this->assertStringStartsWith("Date 'ěě", $sanitized);
        $this->assertSame(510, strlen($sanitized));
        $this->assertJson(json_encode($sanitized, JSON_THROW_ON_ERROR));
    }
}
