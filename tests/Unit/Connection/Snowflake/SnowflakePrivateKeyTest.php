<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Connection\Snowflake;

use Keboola\TableBackendUtils\Connection\Snowflake\SnowflakePrivateKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SnowflakePrivateKeyTest extends TestCase
{
    private const BODY = 'MIIBOgIBAAJBAKj34GkxFhD90vcNLYLInFEX6Ppy1tPf9Cnzj4p4WGeKLs1Pt8Qu'
        . 'KUpRKfFLfRYC9AIKjbJTWit+CqvjWYzvQwECAwEAAQ==';

    /**
     * @return iterable<string, array{string}>
     */
    public static function keyProvider(): iterable
    {
        yield 'single line body' => [self::BODY];

        yield 'body wrapped at 64 chars' => [wordwrap(self::BODY, 64, "\n", true)];

        yield 'body with CRLF' => [str_replace("\n", "\r\n", wordwrap(self::BODY, 64, "\n", true))];

        yield 'body with surrounding whitespace' => ["  \n" . self::BODY . "\n  "];

        yield 'already a full PEM' => [
            "-----BEGIN PRIVATE KEY-----\n"
            . wordwrap(self::BODY, 64, "\n", true)
            . "\n-----END PRIVATE KEY-----\n",
        ];
    }

    #[DataProvider('keyProvider')]
    public function testNormalizeProducesArmoredPem(string $privateKey): void
    {
        $expected = "-----BEGIN PRIVATE KEY-----\n"
            . wordwrap(self::BODY, 64, "\n", true)
            . "\n-----END PRIVATE KEY-----\n";

        self::assertSame($expected, SnowflakePrivateKey::normalize($privateKey));
    }

    public function testNormalizeIsIdempotent(): void
    {
        $once = SnowflakePrivateKey::normalize(self::BODY);

        self::assertSame($once, SnowflakePrivateKey::normalize($once));
    }

    public function testNormalizeWrapsLongBodyAt64Chars(): void
    {
        $normalized = SnowflakePrivateKey::normalize(self::BODY);

        $lines = explode("\n", trim($normalized));
        array_shift($lines);
        array_pop($lines);

        self::assertNotSame([], $lines);
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(64, strlen($line));
        }
        self::assertSame(self::BODY, implode('', $lines));
    }
}
