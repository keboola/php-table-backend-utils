<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Table;

use Keboola\TableBackendUtils\Column\ColumnCollection;

interface TableQueryBuilderInterface
{
    public const CREATE_TABLE_WITH_PRIMARY_KEYS = true;
    public const CREATE_TABLE_WITHOUT_PRIMARY_KEYS = false;

    public function getCreateTempTableCommand(
        string $schemaName,
        string $tableName,
        ColumnCollection $columns,
    ): string;

    public function getDropTableCommand(string $schemaName, string $tableName): string;

    public function getRenameTableCommand(string $schemaName, string $sourceTableName, string $newTableName): string;

    /**
     * Atomically swap the contents of two tables within the same schema.
     *
     * Backends that implement a native, atomic swap (e.g. Snowflake's
     * `ALTER TABLE ... SWAP WITH ...`) return the corresponding SQL statement.
     *
     * Backends without native swap support (e.g. BigQuery) MUST throw
     * `\LogicException` from this method. Callers targeting such backends are
     * expected to emulate the swap via a chain of `getRenameTableCommand()`
     * calls at the handler layer and perform compensation on failure.
     */
    public function getSwapTableCommand(string $schemaName, string $tableA, string $tableB): string;

    public function getTruncateTableCommand(string $schemaName, string $tableName): string;

    /**
     * @param string[] $primaryKeys
     */
    public function getCreateTableCommand(
        string $schemaName,
        string $tableName,
        ColumnCollection $columns,
        array $primaryKeys = [],
    ): string;

    public function getCreateTableCommandFromDefinition(
        TableDefinitionInterface $definition,
        bool $definePrimaryKeys = self::CREATE_TABLE_WITHOUT_PRIMARY_KEYS,
    ): string;
}
