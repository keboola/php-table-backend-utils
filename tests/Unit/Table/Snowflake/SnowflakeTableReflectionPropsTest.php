<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Table\Snowflake;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Keboola\TableBackendUtils\Table\Snowflake\SnowflakeTableReflection;
use Keboola\TableBackendUtils\Table\TableType;
use Keboola\TableBackendUtils\TableNotExistsReflectionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SnowflakeTableReflectionPropsTest extends TestCase
{
    public function testStatsComeFromShowTablesWithoutTouchingInformationSchema(): void
    {
        $queries = [];
        $connection = $this->createConnectionStub($queries, [
            $this->showTablesRow(name: 'orders', rows: '3', bytes: '1024', comment: 'order table'),
        ]);

        $ref = new SnowflakeTableReflection($connection, 'in.c-main', 'orders');
        $stats = $ref->getTableStats();

        self::assertSame(3, $stats->getRowsCount());
        self::assertSame(1024, $stats->getDataSizeBytes());
        self::assertFalse($ref->isTemporary());
        self::assertSame([], $this->informationSchemaQueries($queries));
    }

    public function testExactNameIsPickedOutOfCaseInsensitiveWildcardMatches(): void
    {
        $queries = [];
        $connection = $this->createConnectionStub($queries, [
            $this->showTablesRow(name: 'MY_TABLE', rows: '10', bytes: '10'),
            $this->showTablesRow(name: 'myXtable', rows: '20', bytes: '20'),
            $this->showTablesRow(name: 'my_table', rows: '30', bytes: '30'),
        ]);

        $ref = new SnowflakeTableReflection($connection, 'in.c-main', 'my_table');

        self::assertSame(30, $ref->getRowsCount());
    }

    public function testTemporaryTableIsRecognizedFromKind(): void
    {
        $queries = [];
        $connection = $this->createConnectionStub($queries, [
            $this->showTablesRow(name: 'staging', kind: 'TEMPORARY'),
        ]);

        $ref = new SnowflakeTableReflection($connection, 'in.c-main', 'staging');

        self::assertTrue($ref->isTemporary());
    }

    public function testTransientTableIsNotTemporary(): void
    {
        $queries = [];
        $connection = $this->createConnectionStub($queries, [
            $this->showTablesRow(name: 'orders', kind: 'TRANSIENT'),
        ]);

        $ref = new SnowflakeTableReflection($connection, 'in.c-main', 'orders');

        self::assertFalse($ref->isTemporary());
    }

    public function testExternalTableKeepsItsTableType(): void
    {
        $queries = [];
        $connection = $this->createConnectionStub($queries, [
            $this->showTablesRow(name: 'external', rows: null, bytes: null, isExternal: 'Y'),
        ]);

        $ref = new SnowflakeTableReflection($connection, 'in.c-main', 'external');

        self::assertSame(TableType::SNOWFLAKE_EXTERNAL, $ref->getTableDefinition()->getTableType());
        self::assertSame(0, $ref->getRowsCount());
    }

    public function testViewFallsBackToInformationSchema(): void
    {
        $queries = [];
        $connection = $this->createConnectionStub($queries, [], [
            [
                'TABLE_TYPE' => 'VIEW',
                'BYTES' => '',
                'ROW_COUNT' => '',
                'COMMENT' => '',
                'LAST_ALTERED' => '2026-08-03 10:00:00.000',
            ],
        ]);

        $ref = new SnowflakeTableReflection($connection, 'in.c-main', 'orders_view');

        self::assertTrue($ref->isView());
        self::assertCount(1, $this->informationSchemaQueries($queries));
    }

    public function testRowWithoutShowTablesColumnsFallsBackToInformationSchema(): void
    {
        $queries = [];
        $connection = $this->createConnectionStub($queries, [
            ['name' => 'orders', 'TABLE_TYPE' => 'BASE TABLE', 'ROW_COUNT' => '3'],
        ], [
            [
                'TABLE_TYPE' => 'BASE TABLE',
                'BYTES' => '1024',
                'ROW_COUNT' => '3',
                'COMMENT' => '',
                'LAST_ALTERED' => '2026-08-03 10:00:00.000',
            ],
        ]);

        $ref = new SnowflakeTableReflection($connection, 'in.c-main', 'orders');

        self::assertSame(3, $ref->getRowsCount());
        self::assertCount(1, $this->informationSchemaQueries($queries));
    }

    public function testMissingSchemaMakesShowTablesFailAndStillReportsMissingTable(): void
    {
        $queries = [];
        $connection = $this->createConnectionStub($queries, null, []);

        $ref = new SnowflakeTableReflection($connection, 'gone', 'orders');

        $this->expectException(TableNotExistsReflectionException::class);
        $ref->getRowsCount();
    }

    public function testLastChangeMarkerReadsInformationSchemaOnceOnTopOfShowTables(): void
    {
        $queries = [];
        $connection = $this->createConnectionStub($queries, [
            $this->showTablesRow(name: 'orders'),
        ], [
            [
                'TABLE_TYPE' => 'BASE TABLE',
                'BYTES' => '1024',
                'ROW_COUNT' => '3',
                'COMMENT' => '',
                'LAST_ALTERED' => '2026-08-03 10:00:00.000',
            ],
        ]);

        $ref = new SnowflakeTableReflection($connection, 'in.c-main', 'orders');
        $ref->getTableStatsFromProps();

        self::assertSame('2026-08-03 10:00:00.000', $ref->getLastChangeMarker());
        self::assertSame('2026-08-03 10:00:00.000', $ref->getLastChangeMarker());
        self::assertCount(1, $this->informationSchemaQueries($queries));
    }

    public function testNullLastChangeMarkerIsNotReReadOnEveryCall(): void
    {
        $queries = [];
        $connection = $this->createConnectionStub($queries, [
            $this->showTablesRow(name: 'external', isExternal: 'Y'),
        ], [
            [
                'TABLE_TYPE' => 'EXTERNAL TABLE',
                'BYTES' => '',
                'ROW_COUNT' => '',
                'COMMENT' => '',
                'LAST_ALTERED' => '',
            ],
        ]);

        $ref = new SnowflakeTableReflection($connection, 'in.c-main', 'external');

        self::assertNull($ref->getLastChangeMarker());
        self::assertNull($ref->getLastChangeMarker());
        self::assertCount(1, $this->informationSchemaQueries($queries));
    }

    /**
     * @return array{
     *     name: string,
     *     kind: string,
     *     comment: string|null,
     *     rows: string|null,
     *     bytes: string|null,
     *     is_external: string,
     * }
     */
    private function showTablesRow(
        string $name,
        string $kind = 'TABLE',
        ?string $rows = '1',
        ?string $bytes = '1',
        ?string $comment = '',
        string $isExternal = 'N',
    ): array {
        return [
            'name' => $name,
            'kind' => $kind,
            'comment' => $comment,
            'rows' => $rows,
            'bytes' => $bytes,
            'is_external' => $isExternal,
        ];
    }

    /**
     * @param list<string> $queries
     * @return list<string>
     */
    private function informationSchemaQueries(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            static fn(string $query): bool => str_starts_with($query, 'SELECT TABLE_TYPE'),
        ));
    }

    /**
     * @param list<string> $queries
     * @param list<array<string, string|null>>|null $showTablesResult null makes SHOW TABLES fail
     * @param list<array<string, string|null>> $informationSchemaResult
     * @param-out list<string> $queries
     */
    private function createConnectionStub(
        array &$queries,
        ?array $showTablesResult,
        array $informationSchemaResult = [],
    ): Connection {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')
            ->willReturnCallback(
                static function (string $sql) use (&$queries, $showTablesResult, $informationSchemaResult): array {
                    $queries[] = $sql;

                    if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
                        if ($showTablesResult === null) {
                            throw new DbalException('Schema does not exist or not authorized.');
                        }

                        return $showTablesResult;
                    }
                    if (str_starts_with($sql, 'SELECT TABLE_TYPE')) {
                        return $informationSchemaResult;
                    }
                    if (str_starts_with($sql, 'DESC TABLE')) {
                        return [];
                    }
                    if (str_starts_with($sql, 'SHOW PRIMARY KEYS')) {
                        return [];
                    }

                    throw new RuntimeException(sprintf('Unexpected query "%s".', $sql));
                },
            );

        return $connection;
    }
}
