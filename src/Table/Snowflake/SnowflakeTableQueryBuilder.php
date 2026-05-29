<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Table\Snowflake;

use Keboola\Datatype\Definition\Snowflake;
use Keboola\TableBackendUtils\Column\ColumnCollection;
use Keboola\TableBackendUtils\Column\Snowflake\SnowflakeColumn;
use Keboola\TableBackendUtils\Escaping\Snowflake\SnowflakeQuote;
use Keboola\TableBackendUtils\QueryBuilderException;
use Keboola\TableBackendUtils\Table\TableDefinitionInterface;
use Keboola\TableBackendUtils\Table\TableQueryBuilderInterface;

class SnowflakeTableQueryBuilder implements TableQueryBuilderInterface
{
    private const CANNOT_CHANGE_DEFAULT_VALUE = 'cannotChangeDefaultValue';
    private const CANNOT_CHANGE_SCALE = 'cannotChangeScale';
    private const CANNOT_CHANGE_TYPE = 'cannotChangeType';
    private const CANNOT_DECREASE_LENGTH = 'cannotDecreaseLength';
    private const CANNOT_DECREASE_PRECISION = 'cannotDecreasePrecision';
    private const CANNOT_INTRODUCE_COMPLEX_LENGTH = 'cannotIntroduceComplexLength';
    private const CANNOT_REDUCE_COMPLEX_LENGTH = 'cannotReduceComplexLength';
    private const INVALID_PKS_FOR_TABLE = 'invalidPKs';
    private const INVALID_TABLE_NAME = 'invalidTableName';
    private const EMPTY_COLUMNS_TO_UPDATE = 'emptyColumnsToUpdate';
    public const TEMP_TABLE_PREFIX = '__temp_';

    public function getCreateTempTableCommand(string $schemaName, string $tableName, ColumnCollection $columns): string
    {
        $this->assertStagingTableName($tableName);

        $columnsSqlDefinitions = [];
        /** @var SnowflakeColumn $column */
        foreach ($columns->getIterator() as $column) {
            /** @var Snowflake $columnDefinition */
            $columnDefinition = $column->getColumnDefinition();
            $columnsSqlDefinitions[] = sprintf(
                '%s %s',
                SnowflakeQuote::quoteSingleIdentifier($column->getColumnName()),
                $columnDefinition->getSQLDefinition(),
            );
        }

        $columnsSql = implode(",\n", $columnsSqlDefinitions);

        return sprintf(
            'CREATE TEMPORARY TABLE %s.%s
(
%s
);',
            SnowflakeQuote::quoteSingleIdentifier($schemaName),
            SnowflakeQuote::quoteSingleIdentifier($tableName),
            $columnsSql,
        );
    }

    public function getDropTableCommand(string $schemaName, string $tableName): string
    {
        return sprintf(
            'DROP TABLE %s.%s',
            SnowflakeQuote::quoteSingleIdentifier($schemaName),
            SnowflakeQuote::quoteSingleIdentifier($tableName),
        );
    }

    public function getRenameTableCommand(string $schemaName, string $sourceTableName, string $newTableName): string
    {
        $this->assertTableName($newTableName);

        $quotedDbName = SnowflakeQuote::quoteSingleIdentifier($schemaName);
        return sprintf(
            'ALTER TABLE %s.%s RENAME TO %s.%s',
            $quotedDbName,
            SnowflakeQuote::quoteSingleIdentifier($sourceTableName),
            $quotedDbName,
            SnowflakeQuote::quoteSingleIdentifier($newTableName),
        );
    }

    public function getSwapTableCommand(string $schemaName, string $tableA, string $tableB): string
    {
        $this->assertTableName($tableA);
        $this->assertTableName($tableB);

        $quotedDbName = SnowflakeQuote::quoteSingleIdentifier($schemaName);
        return sprintf(
            'ALTER TABLE %s.%s SWAP WITH %s.%s',
            $quotedDbName,
            SnowflakeQuote::quoteSingleIdentifier($tableA),
            $quotedDbName,
            SnowflakeQuote::quoteSingleIdentifier($tableB),
        );
    }

    public function getTruncateTableCommand(string $schemaName, string $tableName): string
    {
        return sprintf(
            'TRUNCATE TABLE %s.%s',
            SnowflakeQuote::quoteSingleIdentifier($schemaName),
            SnowflakeQuote::quoteSingleIdentifier($tableName),
        );
    }

    /**
     * @inheritDoc
     */
    public function getCreateTableCommand(
        string $schemaName,
        string $tableName,
        ColumnCollection $columns,
        array $primaryKeys = [],
    ): string {
        $this->assertTableName($tableName);

        $columnsSqlDefinitions = [];
        $columnNames = [];
        /** @var SnowflakeColumn $column */
        foreach ($columns->getIterator() as $column) {
            $columnName = $column->getColumnName();
            $columnNames[] = $columnName;
            /** @var Snowflake $columnDefinition */
            $columnDefinition = $column->getColumnDefinition();

            $columnSql = sprintf(
                '%s %s',
                SnowflakeQuote::quoteSingleIdentifier($columnName),
                $columnDefinition->getSQLDefinition(),
            );
            if ($columnDefinition->getDescription() !== null) {
                $columnSql .= sprintf(
                    ' COMMENT %s',
                    SnowflakeQuote::quote($columnDefinition->getDescription()),
                );
            }

            $columnsSqlDefinitions[] = $columnSql;
        }

        // check that all PKs are valid columns
        $pksNotPresentInColumns = array_diff($primaryKeys, $columnNames);
        if ($pksNotPresentInColumns !== []) {
            throw new QueryBuilderException(
                sprintf(
                    'Trying to set %s as PKs but not present in columns',
                    implode(',', $pksNotPresentInColumns),
                ),
                self::INVALID_PKS_FOR_TABLE,
            );
        }

        if ($primaryKeys !== []) {
            $columnsSqlDefinitions[] =
                sprintf(
                    'PRIMARY KEY (%s)',
                    implode(',', array_map(
                        static fn($item) => SnowflakeQuote::quoteSingleIdentifier($item),
                        $primaryKeys,
                    )),
                );
        }

        $columnsSql = implode(",\n", $columnsSqlDefinitions);

        // brackets on single rows because in order to have much more beautiful query at the end
        return sprintf(
            'CREATE TABLE %s.%s
(
%s
);',
            SnowflakeQuote::quoteSingleIdentifier($schemaName),
            SnowflakeQuote::quoteSingleIdentifier($tableName),
            $columnsSql,
        );
    }

    /**
     * @param SnowflakeTableDefinition $definition
     */
    public function getCreateTableCommandFromDefinition(
        TableDefinitionInterface $definition,
        bool $definePrimaryKeys = self::CREATE_TABLE_WITHOUT_PRIMARY_KEYS,
    ): string {
        /** @phpstan-ignore instanceof.alwaysTrue, function.alreadyNarrowedType */
        assert($definition instanceof SnowflakeTableDefinition);
        if ($definition->isTemporary()) {
            return $this->getCreateTempTableCommand(
                $definition->getSchemaName(),
                $definition->getTableName(),
                $definition->getColumnsDefinitions(),
            );
        }

        $sql = $this->getCreateTableCommand(
            $definition->getSchemaName(),
            $definition->getTableName(),
            $definition->getColumnsDefinitions(),
            $definePrimaryKeys === self::CREATE_TABLE_WITH_PRIMARY_KEYS
                ? $definition->getPrimaryKeysNames()
                : [],
        );

        if ($definition->getDescription() === null) {
            return $sql;
        }

        return sprintf(
            "%s\nCOMMENT = %s;",
            rtrim($sql, ';'),
            SnowflakeQuote::quote($definition->getDescription()),
        );
    }

    /**
     * checks that table name has __temp_ prefix which is required for temp tables
     */
    private function assertStagingTableName(string $tableName): void
    {
        $this->assertTableName($tableName);
        if ($tableName === self::TEMP_TABLE_PREFIX || strpos($tableName, self::TEMP_TABLE_PREFIX) !== 0) {
            throw new QueryBuilderException(
                sprintf(
                    'Invalid table name %s: Table must start with __temp_ prefix',
                    $tableName,
                ),
                self::INVALID_TABLE_NAME,
            );
        }
    }

    private function assertTableName(string $tableName): void
    {
        if (preg_match('/^[-_A-Za-z\d$]+$/', $tableName, $out) !== 1) {
            throw new QueryBuilderException(
                sprintf(
                    // phpcs:ignore
                    'Invalid table name %s: Only alphanumeric characters, dash, underscores and dollar signs are allowed.',
                    $tableName,
                ),
                self::INVALID_TABLE_NAME,
            );
        }

        if (ctype_print($tableName) === false) {
            throw new QueryBuilderException(
                sprintf(
                    'Invalid table name %s: Name can contain only printable characters.',
                    $tableName,
                ),
                self::INVALID_TABLE_NAME,
            );
        }
    }

    public static function buildTempTableName(string $realTableName): string
    {
        return self::TEMP_TABLE_PREFIX . $realTableName;
    }

    public function getUpdateColumnFromDefinitionQuery(
        Snowflake $existingColumnDefinition,
        Snowflake $desiredColumnDefinition,
        string $schemaName,
        string $tableName,
        string $columnName,
    ): string {
        $sql = sprintf(
            'ALTER TABLE %s.%s MODIFY ',
            SnowflakeQuote::quoteSingleIdentifier($schemaName),
            SnowflakeQuote::quoteSingleIdentifier($tableName),
        );
        $sqlParts = $this->getUpdateColumnDefinitionSqlParts(
            $existingColumnDefinition,
            $desiredColumnDefinition,
            $columnName,
            null,
        );
        $partsWithColumnPrefix = array_map(function (string $part) use ($columnName) {
            return sprintf(
                'COLUMN %s %s',
                SnowflakeQuote::quoteSingleIdentifier($columnName),
                $part,
            );
        }, $sqlParts);
        return $sql . implode(', ', $partsWithColumnPrefix);
    }

    /**
     * @param array<string, array{
     *     existing: Snowflake,
     *     desired: Snowflake,
     *     updateDefault?: bool,
     *     updateNullable?: bool,
     *     updateDataType?: bool,
     *     updateDescription?: bool,
     * }> $columns
     */
    public function getUpdateColumnsFromDefinitionsQuery(
        string $schemaName,
        string $tableName,
        array $columns,
    ): string {
        if ($columns === []) {
            throw new QueryBuilderException(
                'At least one column update is required.',
                self::EMPTY_COLUMNS_TO_UPDATE,
            );
        }

        $sqlParts = [];
        foreach ($columns as $columnName => $column) {
            foreach ($this->getUpdateColumnDefinitionSqlParts(
                $column['existing'],
                $column['desired'],
                $columnName,
                [
                    'default' => $column['updateDefault'] ?? false,
                    'nullable' => $column['updateNullable'] ?? false,
                    'dataType' => $column['updateDataType'] ?? false,
                    'description' => $column['updateDescription'] ?? false,
                ],
            ) as $sqlPart) {
                $sqlParts[] = sprintf(
                    'COLUMN %s %s',
                    SnowflakeQuote::quoteSingleIdentifier($columnName),
                    $sqlPart,
                );
            }
        }

        if ($sqlParts === []) {
            throw new QueryBuilderException(
                'At least one column attribute update is required.',
                self::EMPTY_COLUMNS_TO_UPDATE,
            );
        }

        return sprintf(
            'ALTER TABLE %s.%s MODIFY %s',
            SnowflakeQuote::quoteSingleIdentifier($schemaName),
            SnowflakeQuote::quoteSingleIdentifier($tableName),
            implode(', ', $sqlParts),
        );
    }

    /**
     * @param array{default: bool, nullable: bool, dataType: bool, description: bool}|null $updates
     * @return string[]
     */
    private function getUpdateColumnDefinitionSqlParts(
        Snowflake $existingColumnDefinition,
        Snowflake $desiredColumnDefinition,
        string $columnName,
        ?array $updates,
    ): array {
        $sqlParts = [];
        // allowed from https://docs.snowflake.com/en/sql-reference/sql/alter-table-column

        // drop default
        if ($this->shouldUpdateDefault($updates)
            && $existingColumnDefinition->getDefault() !== null
            && $desiredColumnDefinition->getDefault() === null) {
            $sqlParts[] = 'DROP DEFAULT';
        } elseif ($this->shouldUpdateDefault($updates)
            && $existingColumnDefinition->getDefault() !== $desiredColumnDefinition->getDefault()) {
            throw new QueryBuilderException(
                sprintf(
                    'Cannot change default value of column "%s" from "%s" to "%s"',
                    $columnName,
                    $existingColumnDefinition->getDefault(),
                    $desiredColumnDefinition->getDefault(),
                ),
                self::CANNOT_CHANGE_DEFAULT_VALUE,
            );
        }

        if ($this->shouldUpdateNullable($updates)
            && $existingColumnDefinition->isNullable() !== $desiredColumnDefinition->isNullable()) {
            $sqlParts[] = $desiredColumnDefinition->isNullable() ? 'DROP NOT NULL' : 'SET NOT NULL';
        }

        $notSameLength = $existingColumnDefinition->getLength() !== $desiredColumnDefinition->getLength();
        $isNewLengthBigger = $existingColumnDefinition->getLength() < $desiredColumnDefinition->getLength();
        $shouldUpdateDataType = $this->shouldUpdateDataType($updates);

        if ($shouldUpdateDataType && $existingColumnDefinition->getType() !== $desiredColumnDefinition->getType()) {
            throw new QueryBuilderException(
                sprintf(
                    'Cannot change type of column "%s" from "%s" to "%s"',
                    $columnName,
                    $existingColumnDefinition->getType(),
                    $desiredColumnDefinition->getType(),
                ),
                self::CANNOT_CHANGE_TYPE,
            );
        }

        // increase precision
        if ($shouldUpdateDataType && $existingColumnDefinition->isTypeWithComplexLength() && $notSameLength) {
            if (!$desiredColumnDefinition->isTypeWithComplexLength()) {
                throw new QueryBuilderException(
                    sprintf(
                        'Cannot reduce column "%s" with complex length "%s" to simple length "%s"',
                        $columnName,
                        $existingColumnDefinition->getLength(),
                        $desiredColumnDefinition->getLength(),
                    ),
                    self::CANNOT_REDUCE_COMPLEX_LENGTH,
                );
            }
            [
                'numeric_precision' => $existingPrecision,
                'numeric_scale' => $existingScale,
            ] = $existingColumnDefinition->getArrayFromLength();
            [
                'numeric_precision' => $desiredPrecision,
                'numeric_scale' => $desiredScale,
            ] = $desiredColumnDefinition->getArrayFromLength();

            if ($existingScale !== $desiredScale) {
                throw new QueryBuilderException(
                    sprintf(
                        'Cannot change scale of a column "%s" from "%s" to "%s"',
                        $columnName,
                        $existingScale,
                        $desiredScale,
                    ),
                    self::CANNOT_CHANGE_SCALE,
                );
            }

            if ($existingPrecision >= $desiredPrecision) {
                throw new QueryBuilderException(
                    sprintf(
                        'Cannot decrease precision of column "%s" from "%s" to "%s"',
                        $columnName,
                        $existingPrecision,
                        $desiredPrecision,
                    ),
                    self::CANNOT_DECREASE_PRECISION,
                );
            }

            $sqlParts[] = sprintf(
                'SET DATA TYPE %s(%s, %s)',
                $desiredColumnDefinition->getType(),
                $desiredPrecision,
                $desiredScale,
            );
        } elseif ($shouldUpdateDataType && $notSameLength && $isNewLengthBigger) {
            if ($desiredColumnDefinition->isTypeWithComplexLength()) {
                throw new QueryBuilderException(
                    sprintf(
                        'Cannot convert column "%s" from simple length "%s" to complex length "%s"',
                        $columnName,
                        $existingColumnDefinition->getLength(),
                        $desiredColumnDefinition->getLength(),
                    ),
                    self::CANNOT_INTRODUCE_COMPLEX_LENGTH,
                );
            }
            // increase length
            $sqlParts[] = sprintf(
                'SET DATA TYPE %s(%s)',
                $desiredColumnDefinition->getType(),
                $desiredColumnDefinition->getLength(),
            );
        } elseif ($shouldUpdateDataType && $notSameLength) {
            throw new QueryBuilderException(
                sprintf(
                    'Cannot decrease length of column "%s" from "%s" to "%s"',
                    $columnName,
                    $existingColumnDefinition->getLength(),
                    $desiredColumnDefinition->getLength(),
                ),
                self::CANNOT_DECREASE_LENGTH,
            );
        }

        if ($this->shouldUpdateDescription($updates, $existingColumnDefinition, $desiredColumnDefinition)) {
            $desiredDescription = $desiredColumnDefinition->getDescription();
            $sqlParts[] = $desiredDescription === null || $desiredDescription === ''
                ? 'UNSET COMMENT'
                : sprintf('COMMENT %s', SnowflakeQuote::quote($desiredDescription));
        }

        return $sqlParts;
    }

    /**
     * @param array{default: bool, nullable: bool, dataType: bool, description: bool}|null $updates
     */
    private function shouldUpdateDefault(?array $updates): bool
    {
        return $updates === null || $updates['default'];
    }

    /**
     * @param array{default: bool, nullable: bool, dataType: bool, description: bool}|null $updates
     */
    private function shouldUpdateNullable(?array $updates): bool
    {
        return $updates === null || $updates['nullable'];
    }

    /**
     * @param array{default: bool, nullable: bool, dataType: bool, description: bool}|null $updates
     */
    private function shouldUpdateDataType(?array $updates): bool
    {
        return $updates === null || $updates['dataType'];
    }

    /**
     * @param array{default: bool, nullable: bool, dataType: bool, description: bool}|null $updates
     */
    private function shouldUpdateDescription(
        ?array $updates,
        Snowflake $existingColumnDefinition,
        Snowflake $desiredColumnDefinition,
    ): bool {
        if ($updates === null) {
            return $existingColumnDefinition->getDescription() !== $desiredColumnDefinition->getDescription();
        }

        return $updates['description'];
    }
}
