<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Snowflake;

/**
 * Snowflake private keys travel through env vars and CI secrets as a single line without the PEM
 * armor, so callers have to rebuild the PEM before OpenSSL accepts it.
 */
final class SnowflakePrivateKey
{
    private const PEM_HEADER = '-----BEGIN PRIVATE KEY-----';
    private const PEM_FOOTER = '-----END PRIVATE KEY-----';
    private const PEM_LINE_LENGTH = 64;

    /**
     * Accepts the bare base64 body as well as a full PEM, so a key stored either way works.
     */
    public static function normalize(string $privateKey): string
    {
        $body = str_replace([self::PEM_HEADER, self::PEM_FOOTER], '', $privateKey);
        $body = (string) preg_replace('/\s+/', '', $body);

        return self::PEM_HEADER . "\n"
            . wordwrap($body, self::PEM_LINE_LENGTH, "\n", true)
            . "\n" . self::PEM_FOOTER . "\n";
    }
}
