<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Exception;

final class OdbcErrorMessage
{
    // A UTF-8 character is at most 4 bytes, so a byte cut can leave at most 3 dangling bytes.
    private const MAX_DANGLING_BYTES = 3;

    // mb_substitute_character() takes a Unicode codepoint, not a literal character.
    private const SUBSTITUTE_CHARACTER_CODEPOINT = 0x3F; // '?'

    /**
     * PHP's ODBC extension keeps the diagnostic in a fixed 512-byte buffer and cuts it by bytes,
     * so a long backend message can end inside a multibyte character. Such a string is not valid
     * UTF-8 and breaks json_encode in every consumer downstream (events, job results, API
     * responses), which replaces the original user-facing error with an application error.
     */
    public static function sanitize(string $message): string
    {
        if (self::isValidUtf8($message)) {
            return $message;
        }

        // Truncation only damages the tail: drop the dangling bytes of the cut character.
        $trimmed = $message;
        for ($i = 0; $i < self::MAX_DANGLING_BYTES; $i++) {
            $trimmed = substr($trimmed, 0, -1);
            if (self::isValidUtf8($trimmed)) {
                return $trimmed;
            }
        }

        // Invalid bytes elsewhere: keep the message readable rather than lose it. mb_scrub()'s
        // replacement character follows the mbstring.substitute_character ini setting, which we
        // don't control in every deployment, so pin it explicitly for a deterministic result.
        $previousSubstitute = mb_substitute_character();
        mb_substitute_character(self::SUBSTITUTE_CHARACTER_CODEPOINT);
        try {
            return mb_scrub($message, 'UTF-8');
        } finally {
            mb_substitute_character($previousSubstitute);
        }
    }

    private static function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}
