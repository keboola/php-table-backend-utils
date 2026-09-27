<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Column\DuckDb;

use Keboola\Datatype\Definition\DuckDb;
use Keboola\TableBackendUtils\Column\DuckDb\DuckDbColumn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DuckDbColumn::class)]
class DuckDbColumnTest extends TestCase
{
    /**
     * DESCRIBE reports some types by DuckDB's canonical name rather than the spelling the
     * column was created with.
     */
    #[DataProvider('provideDescribedTypeSpellings')]
    public function testCreateFromDbMapsTheDescribedSpellingBack(string $described, string $expectedType): void
    {
        $column = DuckDbColumn::createFromDB([
            'column_name' => 'value',
            'column_type' => $described,
            'null' => 'YES',
        ]);

        self::assertSame($expectedType, $column->getColumnDefinition()->getType());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDescribedTypeSpellings(): iterable
    {
        yield 'TIMESTAMPTZ' => ['TIMESTAMP WITH TIME ZONE', DuckDb::TYPE_TIMESTAMPTZ];
        yield 'REAL' => ['FLOAT', DuckDb::TYPE_REAL];
    }

    public function testCreateFromDbSplitsTheParameterListOffTheType(): void
    {
        $column = DuckDbColumn::createFromDB([
            'column_name' => 'amount',
            'column_type' => 'DECIMAL(18,4)',
            'null' => 'NO',
        ]);

        self::assertSame('DECIMAL(18,4) NOT NULL', $column->getColumnDefinition()->getSQLDefinition());
    }
}
