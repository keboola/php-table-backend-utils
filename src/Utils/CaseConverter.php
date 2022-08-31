<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Utils;

final class CaseConverter
{
    public static function stringToUpper(string $string): string
    {
        return strtoupper($string);
    }

    /**
     * @param string[] $arr
     * @return string[]
     */
    public static function arrayToUpper(array $arr): array
    {
        return array_map(static fn(string $string) => strtoupper($string), $arr);
    }
}
