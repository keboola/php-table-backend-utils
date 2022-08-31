<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Utils;

use Keboola\TableBackendUtils\Utils\CaseConverter;
use PHPUnit\Framework\TestCase;

class CaseConverterTest extends TestCase
{
    public function testStringToUpper(): void
    {
        $string = 'testMe_man';
        $result = CaseConverter::stringToUpper($string);
        $this->assertSame('TESTME_MAN', $result);
    }

    public function testArrayToUpper(): void
    {
        $string = ['testMe_man', 'COLUMN_X', 'CoLuMn_xYZ-0'];
        $result = CaseConverter::arrayToUpper($string);
        $this->assertSame(['TESTME_MAN', 'COLUMN_X', 'COLUMN_XYZ-0'], $result);
    }
}
