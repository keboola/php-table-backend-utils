<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Snowflake\Exception;

use RuntimeException;

class PrivateKeyExportFailed extends RuntimeException
{
    public static function export(): self
    {
        // Drain the queue so the errors do not leak into later OpenSSL calls; they may describe key material.
        while (openssl_error_string() !== false);

        return new self('Snowflake private key could not be exported to PKCS#8.');
    }

    public static function tempFile(): self
    {
        return new self('Snowflake private key file could not be created.');
    }

    public static function write(): self
    {
        return new self('Snowflake private key file could not be written.');
    }
}
