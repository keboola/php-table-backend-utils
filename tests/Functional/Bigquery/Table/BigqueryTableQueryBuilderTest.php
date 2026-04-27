<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Functional\Bigquery\Table;

use Generator;
use Google\Cloud\BigQuery\Exception\JobException;
use Google\Cloud\Core\Exception\NotFoundException;
use Keboola\Datatype\Definition\Bigquery;
use Keboola\TableBackendUtils\Column\Bigquery\BigqueryColumn;
use Keboola\TableBackendUtils\Column\ColumnCollection;
use Keboola\TableBackendUtils\QueryBuilderException;
use Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableQueryBuilder;
use Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableReflection;
use Keboola\TableBackendUtils\TableNotExistsReflectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Keboola\TableBackendUtils\Functional\Bigquery\BigqueryBaseCase;

class BigqueryTableQueryBuilderTest extends BigqueryBaseCase
{
    private BigqueryTableQueryBuilder $qb;

    public function setUp(): void
    {
        $this->qb = new BigqueryTableQueryBuilder();
        parent::setUp();

        $this->cleanDataset(self::TEST_SCHEMA);
    }

    /**
     * @param BigqueryColumn[] $columns
     * @param string[]         $primaryKeys
     * @param string[]         $expectedColumnNames
     * @param string[]         $expectedPKs
     */
    #[DataProvider('createTableTestSqlProvider')]
    public function testGetCreateCommand(
        array $columns,
        array $primaryKeys,
        array $expectedColumnNames,
        array $expectedPKs,
        string $expectedSql,
    ): void {
        $this->cleanDataset(self::TEST_SCHEMA);
        $this->createDataset(self::TEST_SCHEMA);

        $sql = $this->qb->getCreateTableCommand(
            self::TEST_SCHEMA,
            self::TABLE_GENERIC,
            new ColumnCollection($columns),
            $primaryKeys,
        );

        self::assertSame($expectedSql, $sql);

        $query = $this->bqClient->query($sql);
        $this->bqClient->runQuery($query);

        // test table properties
        $tableReflection = new BigqueryTableReflection($this->bqClient, self::TEST_SCHEMA, self::TABLE_GENERIC);
        self::assertSame($expectedColumnNames, $tableReflection->getColumnsNames());
        self::assertFalse($tableReflection->isTemporary());
    }

    /**
     * @return \Generator<string, array<string, mixed>>
     */
    public static function createTableTestSqlProvider(): Generator
    {
        $testDb = self::TEST_SCHEMA;
        $tableName = self::TABLE_GENERIC;

        yield 'no keys' => [
            'columns' => [
                BigqueryColumn::createGenericColumn('col1'),
                BigqueryColumn::createGenericColumn('col2'),
            ],
            'primaryKeys' => [],
            'expectedColumnNames' => ['col1', 'col2'],
            'expectedPKs' => [],
            'expectedSql' => <<<EOT
CREATE TABLE `$testDb`.`$tableName` 
(
`col1` STRING DEFAULT '' NOT NULL,
`col2` STRING DEFAULT '' NOT NULL
);
EOT
            ,
        ];

        yield 'single primary key' => [
            'columns' => [
                BigqueryColumn::createGenericColumn('id'),
                BigqueryColumn::createGenericColumn('name'),
            ],
            'primaryKeys' => ['id'],
            'expectedColumnNames' => ['id', 'name'],
            'expectedPKs' => ['id'],
            'expectedSql' => <<<EOT
CREATE TABLE `$testDb`.`$tableName` 
(
`id` STRING DEFAULT '' NOT NULL,
`name` STRING DEFAULT '' NOT NULL,
PRIMARY KEY (`id`) NOT ENFORCED
);
EOT
            ,
        ];

        yield 'composite primary key' => [
            'columns' => [
                BigqueryColumn::createGenericColumn('id'),
                BigqueryColumn::createGenericColumn('type'),
                BigqueryColumn::createGenericColumn('name'),
            ],
            'primaryKeys' => ['id', 'type'],
            'expectedColumnNames' => ['id', 'type', 'name'],
            'expectedPKs' => ['id', 'type'],
            'expectedSql' => <<<EOT
CREATE TABLE `$testDb`.`$tableName` 
(
`id` STRING DEFAULT '' NOT NULL,
`type` STRING DEFAULT '' NOT NULL,
`name` STRING DEFAULT '' NOT NULL,
PRIMARY KEY (`id`,`type`) NOT ENFORCED
);
EOT
            ,
        ];
    }

    public function testInvalidPrimaryKeyThrowsException(): void
    {
        $this->cleanDataset(self::TEST_SCHEMA);
        $this->createDataset(self::TEST_SCHEMA);

        $columns = [
            BigqueryColumn::createGenericColumn('col1'),
            BigqueryColumn::createGenericColumn('col2'),
        ];

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage('Trying to set "nonexistent" as PKs but not present in columns');

        $this->qb->getCreateTableCommand(
            self::TEST_SCHEMA,
            self::TABLE_GENERIC,
            new ColumnCollection($columns),
            ['nonexistent'],
        );
    }

    public function testGetDropTableCommand(): void
    {
        $testDb = $this->getDatasetName();
        $testTable = self::TABLE_GENERIC;
        $this->initTable();

        // reflection to the table
        $ref = new BigqueryTableReflection($this->bqClient, $testDb, $testTable);

        // get, test and run query
        $sql = $this->qb->getDropTableCommand($this->getDatasetName(), self::TABLE_GENERIC);
        self::assertEquals("DROP TABLE `$testDb`.`$testTable`", $sql);
        $this->bqClient->runQuery($this->bqClient->query($sql));

        // test NON existence of old table via counting
        $this->expectException(TableNotExistsReflectionException::class);
        $ref->getRowsCount();
    }

    public function testGetRenameTableCommand(): void
    {
        $testDb = $this->getDatasetName();
        $testTable = self::TABLE_GENERIC;
        $newTable = 'renamed_table';
        $this->cleanDataset($testDb);
        $this->initTable($testDb);

        $refOld = new BigqueryTableReflection($this->bqClient, $testDb, $testTable);
        $refOld->getColumnsNames();

        $sql = $this->qb->getRenameTableCommand($testDb, $testTable, $newTable);
        self::assertEquals("ALTER TABLE `$testDb`.`$testTable` RENAME TO `$newTable`", $sql);
        $this->bqClient->runQuery($this->bqClient->query($sql));

        // New table exists.
        $refNew = new BigqueryTableReflection($this->bqClient, $testDb, $newTable);
        self::assertSame(['id', 'first_name', 'last_name'], $refNew->getColumnsNames());

        // Old name is gone.
        $this->expectException(TableNotExistsReflectionException::class);
        (new BigqueryTableReflection($this->bqClient, $testDb, $testTable))->getColumnsNames();
    }

    public function testSwapEmulatedViaRenameChain(): void
    {
        // BigQuery has no native SWAP; validate that a 3-step rename chain behaves as expected.
        $testDb = $this->getDatasetName();
        $tableA = self::TABLE_GENERIC;
        $tableB = 'swap_target';
        $tmp = '__kbc_swap_tmp_functional';
        $this->cleanDataset($testDb);
        $this->initTable($testDb);

        // create tableB with same shape
        $this->bqClient->runQuery($this->bqClient->query(
            sprintf(
                'CREATE OR REPLACE TABLE `%s`.`%s` (`id` INTEGER, `first_name` STRING(100), `last_name` STRING(100));',
                $testDb,
                $tableB,
            ),
        ));

        $this->insertRowToTable($testDb, $tableA, 1, 'a-only', 'x');
        $this->insertRowToTable($testDb, $tableB, 2, 'b-one', 'y');
        $this->insertRowToTable($testDb, $tableB, 3, 'b-two', 'z');

        $refA = new BigqueryTableReflection($this->bqClient, $testDb, $tableA);
        $refB = new BigqueryTableReflection($this->bqClient, $testDb, $tableB);
        self::assertSame(1, $refA->getRowsCount());
        self::assertSame(2, $refB->getRowsCount());

        // Emulated swap: A -> tmp, B -> A, tmp -> B
        $this->bqClient->runQuery($this->bqClient->query(
            $this->qb->getRenameTableCommand($testDb, $tableA, $tmp),
        ));
        $this->bqClient->runQuery($this->bqClient->query(
            $this->qb->getRenameTableCommand($testDb, $tableB, $tableA),
        ));
        $this->bqClient->runQuery($this->bqClient->query(
            $this->qb->getRenameTableCommand($testDb, $tmp, $tableB),
        ));

        // Reflection caches table info; recreate to observe post-swap state.
        $refAAfter = new BigqueryTableReflection($this->bqClient, $testDb, $tableA);
        $refBAfter = new BigqueryTableReflection($this->bqClient, $testDb, $tableB);
        self::assertSame(2, $refAAfter->getRowsCount());
        self::assertSame(1, $refBAfter->getRowsCount());
    }

    public function testAddAndDropColumn(): void
    {
        $this->cleanDataset(self::TEST_SCHEMA);
        $this->createDataset(self::TEST_SCHEMA);

        $columns = [BigqueryColumn::createGenericColumn('col1'),
            BigqueryColumn::createGenericColumn('col2')];

        $sql = $this->qb->getCreateTableCommand(
            self::TEST_SCHEMA,
            self::TABLE_GENERIC,
            new ColumnCollection($columns),
            [],
        );

        $this->bqClient->runQuery($this->bqClient->query($sql));

        // add column
        $sql = $this->qb->getAddColumnCommand(
            self::TEST_SCHEMA,
            self::TABLE_GENERIC,
            new BigqueryColumn(
                'col3',
                new Bigquery(
                    Bigquery::TYPE_STRING,
                ),
            ),
        );
        $this->assertEquals(
            sprintf(
                'ALTER TABLE `%s`.`%s` ADD COLUMN `col3` STRING',
                self::TEST_SCHEMA,
                self::TABLE_GENERIC,
            ),
            $sql,
        );
        $this->bqClient->runQuery($this->bqClient->query($sql));

        $tableReflection = new BigqueryTableReflection(
            $this->bqClient,
            self::TEST_SCHEMA,
            self::TABLE_GENERIC,
        );
        self::assertSame(['col1', 'col2', 'col3'], $tableReflection->getColumnsNames());

        // drop column
        $sql = $this->qb->getDropColumnCommand(self::TEST_SCHEMA, self::TABLE_GENERIC, 'col2');
        $this->assertEquals(
            sprintf(
                'ALTER TABLE `%s`.`%s` DROP COLUMN `col2`',
                self::TEST_SCHEMA,
                self::TABLE_GENERIC,
            ),
            $sql,
        );
        $this->bqClient->runQuery($this->bqClient->query($sql));

        $tableReflection = new BigqueryTableReflection(
            $this->bqClient,
            self::TEST_SCHEMA,
            self::TABLE_GENERIC,
        );
        self::assertSame(['col1', 'col3'], $tableReflection->getColumnsNames());
    }
}
