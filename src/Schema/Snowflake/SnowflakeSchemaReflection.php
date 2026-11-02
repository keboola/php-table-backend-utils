<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Schema\Snowflake;

use Doctrine\DBAL\Connection;
use Keboola\Datatype\Definition\Snowflake;
use Keboola\TableBackendUtils\Column\ColumnCollection;
use Keboola\TableBackendUtils\Column\Snowflake\SnowflakeColumn;
use Keboola\TableBackendUtils\Escaping\Snowflake\SnowflakeQuote;
use Keboola\TableBackendUtils\ReflectionException;
use Keboola\TableBackendUtils\Schema\SchemaReflectionInterface;
use Keboola\TableBackendUtils\Table\Snowflake\SnowflakeTableDefinition;
use Keboola\TableBackendUtils\Table\TableType;
use RuntimeException;

final class SnowflakeSchemaReflection implements SchemaReflectionInterface
{
    private Connection $connection;

    private string $schemaName;

    // Bounds memory while staying past the 65535-byte Storage limit, so an oversize comment is still detectable.
    public const MAX_COMMENT_LENGTH = 65536;

    private const TYPE_NEED_FALLBACK = [
        Snowflake::TYPE_BINARY,
        Snowflake::TYPE_VARBINARY,
        Snowflake::TYPE_VECTOR,
    ];
    /**
     * @var array<string, array<string, array{name: string,
     *      type: string,
     *      kind: string,
     *      null?: string,
     *      default: ?string,
     *      "primary key": string,
     *      "unique key": string,
     *      check: ?string,
     *      expression: ?string,
     *      comment: ?string,
     *      "policy name": ?string,
     *      "privacy domain": ?string}>>
     */
    private array $fallbackTableCache = [];

    /**
     * @param int|null $maxTotalCommentLength characters of table and column comments getDefinitions()
     *     loads for the whole schema; null loads all of them
     */
    public function __construct(
        Connection $connection,
        string $schemaName,
        private readonly ?int $maxTotalCommentLength = null,
    ) {
        $this->schemaName = $schemaName;
        $this->connection = $connection;
    }

    /**
     * @return string[]
     */
    public function getTablesNames(): array
    {
        /** @var array<array{name:string,kind:string}> $tables */
        $tables = $this->connection->fetchAllAssociative(
            sprintf(
                'SHOW TABLES IN SCHEMA %s',
                SnowflakeQuote::quoteSingleIdentifier($this->schemaName),
            ),
        );

        return array_map(static fn($table) => $table['name'], $tables);
    }

    /**
     * @return string[]
     */
    public function getViewsNames(): array
    {
        /** @var array<array{name:string}> $tables */
        $tables = $this->connection->fetchAllAssociative(
            sprintf(
                'SHOW VIEWS IN SCHEMA %s',
                SnowflakeQuote::quoteSingleIdentifier($this->schemaName),
            ),
        );

        return array_map(static fn($table) => $table['name'], $tables);
    }

    /**
     * @return array<string, SnowflakeTableDefinition>
     */
    public function getDefinitions(): array
    {
        [$budgetCte, $budgetJoin, $commentsLoaded] = $this->commentBudgetSql();

        $informationsQuery = sprintf(
            '%1$sSELECT TABLE_NAME, TABLE_TYPE, BYTES, ROW_COUNT, ' .
            'IFF(%3$s, LEFT(COMMENT, %4$d), NULL) AS "COMMENT", IFF(%3$s, 1, 0) AS "COMMENTS_LOADED" ' .
            'FROM INFORMATION_SCHEMA.TABLES%2$s WHERE TABLE_SCHEMA = %5$s ORDER BY TABLE_NAME;',
            $budgetCte,
            $budgetJoin,
            $commentsLoaded,
            self::MAX_COMMENT_LENGTH,
            SnowflakeQuote::quote($this->schemaName),
        );

        // Snowflake maps in DESC TABLE few data-type aliases to their basic types
        // but in INFORMATION_SCHEMA.COLUMNS table keep data-type aliases
        // here is implemented same mapping as DESC TABLE uses
        $columnsQuery = sprintf(
            <<<SQL
%1\$s
SELECT 
    TABLE_NAME,
    COLUMN_NAME AS "name",
    CASE 
        -- Map string types to VARCHAR
        WHEN DATA_TYPE IN ('CHAR', 'VARCHAR', 'STRING', 'TEXT') THEN 
            'VARCHAR(' || COALESCE(CHARACTER_MAXIMUM_LENGTH::STRING, '16777216') || ')'
        
        -- Map numeric types to NUMBER
        WHEN DATA_TYPE IN ('NUMBER', 'DECIMAL', 'NUMERIC') THEN 
            'NUMBER(' || COALESCE(NUMERIC_PRECISION::STRING, '') || ',' || COALESCE(NUMERIC_SCALE::STRING, '0') || ')'
        
        -- Map date and time types to their respective names - DATE is mapped directly to DATE
        WHEN DATA_TYPE IN ('DATETIME', 'TIME', 'TIMESTAMP', 'TIMESTAMP_LTZ', 'TIMESTAMP_TZ', 'TIMESTAMP_NTZ') THEN 
            DATA_TYPE || '(' || COALESCE(DATETIME_PRECISION::STRING, '') || ')'
        
        -- Map binary and varbinary - Snowflake don't have length for binary in INFORMATION_SCHEMA - handled in code
        WHEN DATA_TYPE IN ('BINARY', 'VARBINARY') THEN
            'BINARY' 
 
        -- Default case for all other types as they are mapped by DESC TABLE direcly to themself
        ELSE DATA_TYPE
    END AS "type",
    COLUMN_DEFAULT AS "default",
    IS_NULLABLE AS "null?",
    IFF(%2\$s, COALESCE(LEFT(COMMENT, %3\$d), ''), NULL) AS "comment",
    IFF(%2\$s, 1, 0) AS "COMMENTS_LOADED"
FROM INFORMATION_SCHEMA.COLUMNS%4\$s
WHERE table_schema = %5\$s
ORDER BY TABLE_NAME, ORDINAL_POSITION;
SQL,
            $budgetCte,
            $commentsLoaded,
            self::MAX_COMMENT_LENGTH,
            $budgetJoin,
            SnowflakeQuote::quote($this->schemaName),
        );

        /** @var string $databaseName */
        $databaseName = $this->connection->fetchOne('SELECT CURRENT_DATABASE()');
        $primaryKeyQuery = sprintf(
            'SHOW PRIMARY KEYS IN SCHEMA %s.%s',
            SnowflakeQuote::quoteSingleIdentifier($databaseName),
            SnowflakeQuote::quoteSingleIdentifier($this->schemaName),
        );

        /** @var array<int, array{
         *     TABLE_NAME: string,
         *     TABLE_TYPE: string,
         *     BYTES: int,
         *     ROW_COUNT: int,
         *     COMMENT: string|null,
         *     COMMENTS_LOADED: int|string}> $informations */
        $informations = $this->connection->fetchAllAssociative($informationsQuery);

        // short-circuit > no tables no need to continue
        if (count($informations) === 0) {
            return [];
        }

        /** @var array<int, array{
         *     TABLE_NAME: string,
         *     name: string,
         *     type: string,
         *     default: string,
         *     null?: string,
         *     comment: string|null,
         *     COMMENTS_LOADED: int|string}> $columns */
        $columns = $this->connection->fetchAllAssociative($columnsQuery);
        /** @var array<int, array{
         *     created_on: string,
         *     database_name: string,
         *     schema_name: string,
         *     table_name: string,
         *     column_name: string,
         *     key_sequence: int,
         *     constraint_name: string,
         *     rely: bool,
         *     comment: ?string}> $primaryKeys */
        $primaryKeys = $this->connection->fetchAllAssociative($primaryKeyQuery);

        $tables = [];

        foreach ($informations as $information) {
            $tableKey = md5($information['TABLE_NAME']);
            $tables[$tableKey]['PROPS'] = $information;
            $tables[$tableKey]['COMMENTS_LOADED'] = (int) $information['COMMENTS_LOADED'] === 1;

            switch (strtoupper($information['TABLE_TYPE'])) {
                case 'BASE TABLE':
                    $tables[$tableKey]['PROPS']['TEMPORARY'] = false;
                    $tables[$tableKey]['PROPS']['TABLE_TYPE'] = TableType::TABLE;
                    break;
                case 'EXTERNAL TABLE':
                    $tables[$tableKey]['PROPS']['TEMPORARY'] = false;
                    $tables[$tableKey]['PROPS']['TABLE_TYPE'] = TableType::SNOWFLAKE_EXTERNAL;
                    break;
                case 'LOCAL TEMPORARY':
                case 'TEMPORARY TABLE':
                    $tables[$tableKey]['PROPS']['TEMPORARY'] = true;
                    $tables[$tableKey]['PROPS']['TABLE_TYPE'] = TableType::TABLE;
                    break;
                case 'VIEW':
                    $tables[$tableKey]['PROPS']['TEMPORARY'] = false;
                    $tables[$tableKey]['PROPS']['TABLE_TYPE'] = TableType::VIEW;
                    break;
                default:
                    throw new ReflectionException(sprintf(
                        'Table type "%s" is not known.',
                        $information['TABLE_TYPE'],
                    ));
            }
        }

        // the budget is evaluated per query, so a schema change between the two queries can disagree
        foreach ($columns as $column) {
            $tableKey = md5($column['TABLE_NAME']);
            if (array_key_exists($tableKey, $tables) && (int) $column['COMMENTS_LOADED'] !== 1) {
                $tables[$tableKey]['COMMENTS_LOADED'] = false;
            }
        }

        foreach ($columns as $column) {
            $tableKey = md5($column['TABLE_NAME']);
            if (!array_key_exists($tableKey, $tables)) {
                // Should not happen, but Snowflake have bug in SHOW PRIMARY KEYS to show key for table without perms
                // so skipping also here to be sure
                continue;
            }
            // Offset 'null?' does not exist on
            // array{TABLE_NAME: string, name: string, type: string, default: string, null?: string}.
            // @phpstan-ignore-next-line
            $column['null?'] = ($column['null?'] === 'YES' ? 'Y' : 'N');
            if (!$tables[$tableKey]['COMMENTS_LOADED']) {
                $column['comment'] = null;
            }
            unset($column['COMMENTS_LOADED']);

            if (in_array($column['type'], self::TYPE_NEED_FALLBACK, true)) {
                assert(is_string($column['TABLE_NAME']) && is_string($column['name']));
                $tables[$tableKey]['COLUMNS'][] = $this->fallbackColumnType(
                    $column['TABLE_NAME'],
                    $column['name'],
                    $tables[$tableKey]['COMMENTS_LOADED'],
                );
            } else {
                $tables[$tableKey]['COLUMNS'][] = SnowflakeColumn::createFromDB($column);
            }
        }

        foreach ($primaryKeys as $primaryKey) {
            if ($primaryKey['schema_name'] !== $this->schemaName) {
                continue;
            }
            $tableKey = md5($primaryKey['table_name']);
            if (!array_key_exists($tableKey, $tables)) {
                // Snowflake can show primary keys for table you don't have permissions for
                // Skipping this table
                continue;
            }
            $tables[$tableKey]['PRIMARY_KEYS'][] = $primaryKey['column_name'];
        }

        $definitions = [];
        foreach ($tables as $table) {
            if (!array_key_exists('PROPS', $table)) { // @phpstan-ignore function.alreadyNarrowedType
                throw new ReflectionException(sprintf('Malformed table definition: %s', json_encode($table)));
            }
            $tableName = $table['PROPS']['TABLE_NAME'];
            $tableComment = $table['PROPS']['COMMENT'];
            $commentsLoaded = $table['COMMENTS_LOADED'];
            $definitions[$tableName] = new SnowflakeTableDefinition(
                $this->schemaName,
                $tableName,
                $table['PROPS']['TEMPORARY'],
                new ColumnCollection($table['COLUMNS'] ?? []),
                $table['PRIMARY_KEYS'] ?? [],
                $table['PROPS']['TABLE_TYPE'],
                $tableComment === null || $tableComment === '' || !$commentsLoaded ? null : $tableComment,
                $commentsLoaded,
            );
        }
        return $definitions;
    }

    /**
     * Comments are loaded for whole tables in name order while their running total fits the budget;
     * the rest of the schema gets none, so the memory taken by comments stays bounded.
     *
     * @return array{string, string, string} the CTE, the join and the "comments loaded" condition
     */
    private function commentBudgetSql(): array
    {
        if ($this->maxTotalCommentLength === null) {
            return ['', '', 'TRUE'];
        }

        $cte = sprintf(
            <<<SQL
WITH COLUMN_COMMENT_SIZES AS (
    SELECT TABLE_NAME AS SIZE_TABLE_NAME, SUM(COALESCE(LENGTH(LEFT(COMMENT, %1\$d)), 0)) AS COLUMNS_SIZE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = %2\$s
    GROUP BY TABLE_NAME
), COMMENT_BUDGET AS (
    SELECT
        TABLE_NAME AS BUDGET_TABLE_NAME,
        SUM(COALESCE(LENGTH(LEFT(COMMENT, %1\$d)), 0) + COALESCE(COLUMNS_SIZE, 0)) OVER (
            ORDER BY TABLE_NAME ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
        ) <= %3\$d AS COMMENTS_WITHIN_BUDGET
    FROM INFORMATION_SCHEMA.TABLES
    LEFT JOIN COLUMN_COMMENT_SIZES ON SIZE_TABLE_NAME = TABLE_NAME
    WHERE TABLE_SCHEMA = %2\$s
)

SQL,
            self::MAX_COMMENT_LENGTH,
            SnowflakeQuote::quote($this->schemaName),
            $this->maxTotalCommentLength,
        );

        return [
            $cte,
            ' LEFT JOIN COMMENT_BUDGET ON BUDGET_TABLE_NAME = TABLE_NAME',
            'COALESCE(COMMENTS_WITHIN_BUDGET, FALSE)',
        ];
    }

    /**
     * Snowflake does not provide length information for the datatypes listed in
     * self::TYPE_NEED_FALLBACK in INFORMATION_SCHEMA.COLUMNS (or anywhere else in INFORMATION_SCHEMA).
     * As a result, we must run DESC TABLE on tables that contain columns of these types in order
     * to retrieve all the necessary information to properly construct the SnowflakeColumn class.
     */
    private function fallbackColumnType(string $tableName, string $columnName, bool $commentsLoaded): SnowflakeColumn
    {
        $tableKey = md5($tableName);
        if (!array_key_exists($tableKey, $this->fallbackTableCache)) {
            /**
             * @var array<array{
             *     name: string,
             *     type: string,
             *     kind: string,
             *     "null?": string,
             *     default: ?string,
             *     "primary key": string,
             *     "unique key": string,
             *     check: ?string,
             *     expression: ?string,
             *     comment: ?string,
             *     "policy name": ?string,
             *     "privacy domain": ?string}> $tableDesc
             */
            $tableDesc = $this->connection->fetchAllAssociative(sprintf(
                'DESC TABLE %s.%s',
                SnowflakeQuote::quoteSingleIdentifier($this->schemaName),
                SnowflakeQuote::quoteSingleIdentifier($tableName),
            ));
            $this->fallbackTableCache[$tableKey] = array_column($tableDesc, null, 'name');
        }

        $column = $this->fallbackTableCache[$tableKey][$columnName];
        if (!$commentsLoaded) {
            $column['comment'] = null;
        }

        // Offset 'null?' does not exist on
        // @phpstan-ignore-next-line
        return SnowflakeColumn::createFromDB($column);
    }
}
