<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Table;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLServer2012Platform;
use Keboola\TableBackendUtils\Column\ColumnCollection;
use Keboola\TableBackendUtils\Column\ColumnInterface;
use Keboola\TableBackendUtils\QueryBuilderException;

class SynapseTableQueryBuilder implements TableQueryBuilderInterface
{
    /** @var SQLServer2012Platform|AbstractPlatform */
    private $platform;

    public function __construct(Connection $connection)
    {
        $this->platform = $connection->getDatabasePlatform();
    }

    public function getCreateTempTableCommand(
        string $schemaName,
        string $tableName,
        ColumnCollection $columns
    ): string {
        $this->assertTemporaryTable($tableName);

        $columnsSql = [];
        foreach ($columns as $column) {
            $columnsSql[] = sprintf(
                '%s %s',
                $this->platform->quoteSingleIdentifier($column->getColumnName()),
                $column->getColumnDefinition()->getSQLDefinition()
            );
        }

        return sprintf(
            'CREATE TABLE %s.%s (%s) WITH (HEAP, LOCATION = USER_DB)',
            $this->platform->quoteSingleIdentifier($schemaName),
            $this->platform->quoteSingleIdentifier($tableName),
            implode(', ', $columnsSql)
        );
    }

    private function assertTemporaryTable(string $tableName): void
    {
        if (strpos($tableName, '#') !== 0 || $tableName === '#') {
            throw new QueryBuilderException(
                sprintf(
                // phpcs:ignore
                    'Temporary table name invalid, temporary table name must start with "#" a not be empty "%s" supplied.',
                    $tableName
                ),
                QueryBuilderException::STRING_CODE_INVALID_TEMP_TABLE
            );
        }
    }

    public function getCreateTableCommand(
        string $schemaName,
        string $tableName,
        ColumnCollection $columns,
        array $primaryKeys = []
    ): string {
        $columnsSql = [];
        foreach ($columns as $column) {
            if ($column->getColumnName() === ColumnInterface::TIMESTAMP_COLUMN_NAME) {
                $columnsSql[] = sprintf('[%s] DATETIME2', ColumnInterface::TIMESTAMP_COLUMN_NAME);
                continue;
            }
            $columnsSql[] = sprintf(
                '%s %s',
                $this->platform->quoteSingleIdentifier($column->getColumnName()),
                $column->getColumnDefinition()->getSQLDefinition()
            );
        }

        $primaryKeySql = '';
        if (!empty($primaryKeys)) {
            $quotedPrimaryKeys = array_map(function ($columnName) {
                return $this->platform->quoteSingleIdentifier($columnName);
            }, $primaryKeys);
            $primaryKeySql = sprintf(
                ', PRIMARY KEY NONCLUSTERED(%s) NOT ENFORCED',
                implode(',', $quotedPrimaryKeys)
            );
        }

        return sprintf(
            'CREATE TABLE %s.%s (%s%s)',
            $this->platform->quoteSingleIdentifier($schemaName),
            $this->platform->quoteSingleIdentifier($tableName),
            implode(', ', $columnsSql),
            $primaryKeySql
        );
    }

    public function getDropTableCommand(
        string $schemaName,
        string $tableName
    ): string {
        return sprintf(
            'DROP TABLE %s.%s',
            $this->platform->quoteSingleIdentifier($schemaName),
            $this->platform->quoteSingleIdentifier($tableName)
        );
    }

    public function getRenameTableCommand(
        string $schemaName,
        string $sourceTableName,
        string $newTableName
    ): string {
        return sprintf(
            'RENAME OBJECT %s.%s TO %s',
            $this->platform->quoteSingleIdentifier($schemaName),
            $this->platform->quoteSingleIdentifier($sourceTableName),
            $this->platform->quoteSingleIdentifier($newTableName)
        );
    }

    public function getTruncateTableCommand(
        string $schemaName,
        string $tableName
    ): string {
        return sprintf(
            'TRUNCATE TABLE %s.%s',
            $this->platform->quoteSingleIdentifier($schemaName),
            $this->platform->quoteSingleIdentifier($tableName)
        );
    }
}
