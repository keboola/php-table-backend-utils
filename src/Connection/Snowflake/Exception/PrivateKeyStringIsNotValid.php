<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Snowflake\Exception;

use RuntimeException;

class PrivateKeyStringIsNotValid extends RuntimeException
{
    /**
     * Drains the OpenSSL error queue into the message - without it the exception carries no text
     * at all and a misconfigured key looks like an empty failure.
     */
    public static function fromOpenSslError(): self
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return new self(sprintf(
            'Snowflake private key is not a valid PEM private key%s',
            $errors === [] ? '.' : ': ' . implode('; ', $errors),
        ));
    }
}
