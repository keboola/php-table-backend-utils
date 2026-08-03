<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Table\Snowflake;

use Generator;
use Keboola\Datatype\Definition\Snowflake;
use Keboola\TableBackendUtils\Column\ColumnCollection;
use Keboola\TableBackendUtils\Column\Snowflake\SnowflakeColumn;
use Keboola\TableBackendUtils\QueryBuilderException;
use Keboola\TableBackendUtils\Table\Snowflake\SnowflakeTableDefinition;
use Keboola\TableBackendUtils\Table\Snowflake\SnowflakeTableQueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SnowflakeTableQueryBuilder::class)]
#[UsesClass(ColumnCollection::class)]
class SnowflakeTableQueryBuilderTest extends TestCase
{
    private SnowflakeTableQueryBuilder $qb;

    public function setUp(): void
    {
        $this->qb = new SnowflakeTableQueryBuilder();
    }

    /**
     * @param  SnowflakeColumn[] $columns
     * @param  string[]          $PKs
     * @throws \Exception
     */
    #[DataProvider('createTableInvalidPKsProvider')]
    public function testGetCreateCommandWithInvalidPks(array $columns, array $PKs, string $exceptionString): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage($exceptionString);
        $this->qb->getCreateTableCommand('testDb', 'testTable', new ColumnCollection($columns), $PKs);
        self::fail('Should fail because of invalid PKs');
    }

    /**
     * @return \Generator<string, mixed, mixed, mixed>
     */
    public static function createTableInvalidPKsProvider(): Generator
    {
        yield 'key of ouf columns' => [
            'columns' => [
                SnowflakeColumn::createGenericColumn('col1'),
                SnowflakeColumn::createGenericColumn('col2'),
            ],
            'PKs' => ['colNotExisting'],
            'exceptionString' => 'Trying to set colNotExisting as PKs but not present in columns',
        ];
    }

    public function testCreateTableWithInvalidTableName(): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage(
            'Invalid table name testTab.: Only alphanumeric characters, dash,'
                . ' underscores and dollar signs are allowed.',
        );
        $this->qb->getCreateTableCommand('testDb', 'testTab.', new ColumnCollection([]));
        self::fail('Should fail because of invalid table name');
    }

    public function testCreateTableWithColumnDescription(): void
    {
        $sql = $this->qb->getCreateTableCommand(
            'testDb',
            'testTable',
            new ColumnCollection([
                new SnowflakeColumn(
                    'id',
                    new Snowflake(Snowflake::TYPE_VARCHAR, [
                        'nullable' => false,
                        'description' => 'Customer-facing column description',
                    ]),
                ),
            ]),
        );

        self::assertSame(
            <<<'SQL'
CREATE TABLE "testDb"."testTable"
(
"id" VARCHAR NOT NULL COMMENT 'Customer-facing column description'
);
SQL,
            $sql,
        );
    }

    public function testCreateTableFromDefinitionWithTableDescription(): void
    {
        $sql = $this->qb->getCreateTableCommandFromDefinition(new SnowflakeTableDefinition(
            'testDb',
            'testTable',
            false,
            new ColumnCollection([
                new SnowflakeColumn('id', new Snowflake(Snowflake::TYPE_VARCHAR)),
            ]),
            [],
            description: 'Curated customer table',
        ));

        self::assertSame(
            <<<'SQL'
CREATE TABLE "testDb"."testTable"
(
"id" VARCHAR
)
COMMENT = 'Curated customer table';
SQL,
            $sql,
        );
    }

    public function testRenameTableWithInvalidTableName(): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage(
            'Invalid table name testTab.: Only alphanumeric characters, dash,'
                . ' underscores and dollar signs are allowed.',
        );
        $this->qb->getRenameTableCommand('testDb', 'testTab', 'testTab.');
        self::fail('Should fail because of invalid table name');
    }

    public function testGetRenameTable(): void
    {
        $renameCommand = $this->qb->getRenameTableCommand('testDb', 'testTable', 'newTable');
        self::assertEquals('ALTER TABLE "testDb"."testTable" RENAME TO "testDb"."newTable"', $renameCommand);
    }

    public function testGetSwapTable(): void
    {
        $swapCommand = $this->qb->getSwapTableCommand('testDb', 'tableA', 'tableB');
        self::assertEquals('ALTER TABLE "testDb"."tableA" SWAP WITH "testDb"."tableB"', $swapCommand);
    }

    public function testSwapTableWithInvalidTableName(): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage(
            'Invalid table name testTab.: Only alphanumeric characters, dash,'
                . ' underscores and dollar signs are allowed.',
        );
        $this->qb->getSwapTableCommand('testDb', 'testTab.', 'otherTable');
        self::fail('Should fail because of invalid table name');
    }

    public function testGetDropTable(): void
    {
        $dropTableCommand = $this->qb->getDropTableCommand('testDb', 'testTable');
        self::assertEquals('DROP TABLE "testDb"."testTable"', $dropTableCommand);
    }

    public function testGetTruncateTable(): void
    {
        $dropTableCommand = $this->qb->getTruncateTableCommand('testDb', 'testTable');
        self::assertEquals('TRUNCATE TABLE "testDb"."testTable"', $dropTableCommand);
    }

    #[DataProvider('provideGetColumnDefinitionUpdate')]
    public function testGetColumnDefinitionUpdate(
        Snowflake $existingColumn,
        Snowflake $desiredColumn,
        string $expectedQuery,
    ): void {
        $existingColumnDefinition = $existingColumn;
        $desiredColumnDefinition = $desiredColumn;
        $sql = $this->qb->getUpdateColumnFromDefinitionQuery(
            $existingColumnDefinition,
            $desiredColumnDefinition,
            'testDb',
            'testTable',
            'testColumn',
        );
        self::assertEquals(
            $expectedQuery,
            $sql,
        );
    }

    public function testGetColumnsDefinitionsUpdateBuildsSingleModifyStatement(): void
    {
        $sql = $this->qb->getUpdateColumnsFromDefinitionsQuery(
            'testDb',
            'testTable',
            [
                'amount' => [
                    'existing' => new Snowflake('NUMERIC', [
                        'length' => '12,2',
                        'nullable' => false,
                        'default' => '',
                    ]),
                    'desired' => new Snowflake('NUMERIC', [
                        'length' => '14,2',
                        'nullable' => true,
                        'default' => '',
                        'description' => 'Net amount in USD',
                    ]),
                    'updateDataType' => true,
                    'updateNullable' => true,
                    'updateDescription' => true,
                ],
                'legacy_note' => [
                    'existing' => new Snowflake('VARCHAR', [
                        'length' => '255',
                        'nullable' => true,
                        'default' => '',
                        'description' => 'Legacy note',
                    ]),
                    'desired' => new Snowflake('VARCHAR', [
                        'length' => '255',
                        'nullable' => true,
                        'default' => '',
                    ]),
                    'updateDescription' => true,
                ],
            ],
        );

        self::assertSame(
            'ALTER TABLE "testDb"."testTable" MODIFY COLUMN "amount" DROP NOT NULL, '
            . 'COLUMN "amount" SET DATA TYPE NUMERIC(14, 2), '
            . 'COLUMN "amount" COMMENT \'Net amount in USD\', '
            . 'COLUMN "legacy_note" UNSET COMMENT',
            $sql,
        );
    }

    public function testGetColumnsDefinitionsUpdateRequiresColumns(): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage('At least one column update is required.');

        $this->qb->getUpdateColumnsFromDefinitionsQuery('testDb', 'testTable', []);
    }

    public function testGetColumnDefinitionUpdateTruncatesLongComment(): void
    {
        $sql = $this->qb->getUpdateColumnFromDefinitionQuery(
            new Snowflake('VARCHAR', ['nullable' => true, 'description' => null]),
            new Snowflake('VARCHAR', [
                'nullable' => true,
                'description' => str_repeat('x', SnowflakeTableQueryBuilder::COLUMN_COMMENT_MAX_LENGTH + 1),
            ]),
            'testDb',
            'testTable',
            'testColumn',
        );

        self::assertSame(
            sprintf(
                'ALTER TABLE "testDb"."testTable" MODIFY COLUMN "testColumn" COMMENT \'%s\'',
                str_repeat('x', SnowflakeTableQueryBuilder::COLUMN_COMMENT_MAX_LENGTH),
            ),
            $sql,
        );
    }

    #[DataProvider('provideInvalidGetColumnDefinitionUpdate')]
    public function testInvalidGetColumnDefinitionUpdate(
        Snowflake $existingColumn,
        Snowflake $desiredColumn,
        string $expectedExceptionMessage,
    ): void {
        $existingColumnDefinition = $existingColumn;
        $desiredColumnDefinition = $desiredColumn;
        $this->expectExceptionMessage($expectedExceptionMessage);
        $this->qb->getUpdateColumnFromDefinitionQuery(
            $existingColumnDefinition,
            $desiredColumnDefinition,
            'testDb',
            'testTable',
            'testColumn',
        );
    }

    /**
     * @return \Generator<string, array{Snowflake,Snowflake,string}>
     */
    public static function provideGetColumnDefinitionUpdate(): Generator
    {
        yield 'drop default' => [
            new Snowflake('NUMERIC', ['length' => '12,8', 'nullable' => true, 'default' => '10']),
            new Snowflake('NUMERIC', ['length' => '12,8', 'nullable' => true, 'default' => null]),
            /** @lang Snowflake */
            'ALTER TABLE "testDb"."testTable" MODIFY COLUMN "testColumn" DROP DEFAULT',
        ];
        yield 'add nullable' => [
            new Snowflake('NUMERIC', ['length' => '12,8', 'nullable' => false, 'default' => '']),
            new Snowflake('NUMERIC', ['length' => '12,8', 'nullable' => true, 'default' => '']),
            /** @lang Snowflake */
            'ALTER TABLE "testDb"."testTable" MODIFY COLUMN "testColumn" DROP NOT NULL',
        ];
        yield 'drop nullable' => [
            new Snowflake('NUMERIC', ['length' => '12,8', 'nullable' => true, 'default' => '']),
            new Snowflake('NUMERIC', ['length' => '12,8', 'nullable' => false, 'default' => '']),
            /** @lang Snowflake */
            'ALTER TABLE "testDb"."testTable" MODIFY COLUMN "testColumn" SET NOT NULL',
        ];
        yield 'increase length of text column' => [
            new Snowflake('VARCHAR', ['length' => '12', 'nullable' => true, 'default' => '']),
            new Snowflake('VARCHAR', ['length' => '38', 'nullable' => true, 'default' => '']),
            /** @lang Snowflake */
            'ALTER TABLE "testDb"."testTable" MODIFY COLUMN "testColumn" SET DATA TYPE VARCHAR(38)',
        ];
        yield 'increase precision of numeric column' => [
            new Snowflake('NUMERIC', ['length' => '12,8', 'nullable' => true, 'default' => '']),
            new Snowflake('NUMERIC', ['length' => '14,8', 'nullable' => true, 'default' => '']),
            /** @lang Snowflake */
            'ALTER TABLE "testDb"."testTable" MODIFY COLUMN "testColumn" SET DATA TYPE NUMERIC(14, 8)',
        ];
        yield 'set description' => [
            new Snowflake('VARCHAR', ['nullable' => true, 'description' => null]),
            new Snowflake('VARCHAR', ['nullable' => true, 'description' => 'Customer name']),
            /** @lang Snowflake */
            'ALTER TABLE "testDb"."testTable" MODIFY COLUMN "testColumn" COMMENT \'Customer name\'',
        ];
        yield 'unset description' => [
            new Snowflake('VARCHAR', ['nullable' => true, 'description' => 'Customer name']),
            new Snowflake('VARCHAR', ['nullable' => true, 'description' => null]),
            /** @lang Snowflake */
            'ALTER TABLE "testDb"."testTable" MODIFY COLUMN "testColumn" UNSET COMMENT',
        ];
        yield 'full set of changes (increase precision, drop nullable, drop default)' => [
            new Snowflake('NUMERIC', ['length' => '12,8', 'nullable' => true, 'default' => 'grunbread']),
            new Snowflake('NUMERIC', ['length' => '14,8', 'nullable' => false, 'default' => '']),
            /** @lang Snowflake */
            'ALTER TABLE "testDb"."testTable" MODIFY COLUMN "testColumn" DROP DEFAULT, '
            . 'COLUMN "testColumn" SET NOT NULL, COLUMN "testColumn" SET DATA TYPE NUMERIC(14, 8)',
        ];
    }

    public static function provideInvalidGetColumnDefinitionUpdate(): Generator
    {
        yield 'add default' => [
            new Snowflake('VARCHAR', ['length' => '10', 'nullable' => true, 'default' => '']),
            new Snowflake('VARCHAR', ['length' => '10', 'nullable' => true, 'default' => 'Bedight']),
            'Cannot change default value of column "testColumn" from "" to "Bedight"',
        ];
        yield 'change default' => [
            new Snowflake('VARCHAR', ['length' => '10', 'nullable' => true, 'default' => 'Bedight']),
            new Snowflake('VARCHAR', ['length' => '10', 'nullable' => true, 'default' => 'Brabble']),
            'Cannot change default value of column "testColumn" from "Bedight" to "Brabble"',
        ];
        yield 'descrease length of string' => [
            new Snowflake('VARCHAR', ['length' => '10', 'nullable' => true, 'default' => 'Bedight']),
            new Snowflake('VARCHAR', ['length' => '8', 'nullable' => true, 'default' => 'Bedight']),
            'Cannot decrease length of column "testColumn" from "10" to "8"',
        ];
        yield 'descrease precision of number' => [
            new Snowflake('NUMERIC', ['length' => '12,8', 'nullable' => true, 'default' => '']),
            new Snowflake('NUMERIC', ['length' => '10,8', 'nullable' => true, 'default' => '']),
            'Cannot decrease precision of column "testColumn" from "12" to "10"',
        ];
        yield 'change scale of number' => [
            new Snowflake('NUMERIC', ['length' => '12,8', 'nullable' => true, 'default' => '']),
            new Snowflake('NUMERIC', ['length' => '12,10', 'nullable' => true, 'default' => '']),
            'Cannot change scale of a column "testColumn" from "8" to "10"',
        ];
        yield 'change type' => [
            new Snowflake('VARCHAR', ['length' => '255', 'nullable' => true, 'default' => '']),
            new Snowflake('NUMBER', ['length' => '10,2', 'nullable' => true, 'default' => '']),
            'Cannot change type of column "testColumn" from "VARCHAR" to "NUMBER"',
        ];
    }

    public function testGetAddColumnCommand(): void
    {
        $sql = $this->qb->getAddColumnCommand(
            'in.c-main',
            'orders',
            new SnowflakeColumn('amount', new Snowflake('NUMBER', [
                'length' => '10,2',
                'nullable' => false,
                'default' => '0',
            ])),
        );

        self::assertSame(
            'ALTER TABLE "in.c-main"."orders" ADD COLUMN "amount" NUMBER (10,2) NOT NULL DEFAULT 0',
            $sql,
        );
    }

    public function testGetAddColumnCommandForGenericColumn(): void
    {
        $sql = $this->qb->getAddColumnCommand(
            'in.c-main',
            'orders',
            SnowflakeColumn::createGenericColumn('note'),
        );

        self::assertSame(
            'ALTER TABLE "in.c-main"."orders" ADD COLUMN "note" VARCHAR NOT NULL DEFAULT \'\'',
            $sql,
        );
    }

    public function testGetAddColumnCommandWithDescription(): void
    {
        $sql = $this->qb->getAddColumnCommand(
            'in.c-main',
            'orders',
            new SnowflakeColumn('note', new Snowflake('VARCHAR', [
                'length' => '255',
                'nullable' => true,
                'description' => "Customer's note",
            ])),
        );

        self::assertSame(
            'ALTER TABLE "in.c-main"."orders" ADD COLUMN "note" VARCHAR (255) COMMENT \'Customer\\\'s note\'',
            $sql,
        );
    }

    public function testGetAddColumnCommandTruncatesDescriptionToSnowflakeCommentLimit(): void
    {
        $description = str_repeat('x', SnowflakeTableQueryBuilder::COLUMN_COMMENT_MAX_LENGTH + 10);

        $sql = $this->qb->getAddColumnCommand(
            'in.c-main',
            'orders',
            new SnowflakeColumn('note', new Snowflake('VARCHAR', [
                'nullable' => true,
                'description' => $description,
            ])),
        );

        self::assertStringContainsString(
            sprintf('COMMENT \'%s\'', str_repeat('x', SnowflakeTableQueryBuilder::COLUMN_COMMENT_MAX_LENGTH)),
            $sql,
        );
    }
}
