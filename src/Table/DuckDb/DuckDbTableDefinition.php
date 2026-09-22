<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Table\DuckDb;

use Keboola\TableBackendUtils\Column\ColumnCollection;
use Keboola\TableBackendUtils\Column\ColumnInterface;
use Keboola\TableBackendUtils\Table\TableDefinitionInterface;
use Keboola\TableBackendUtils\Table\TableType;

/**
 * A DuckLake table inside a bucket schema.
 *
 * `primaryKeysNames` is carried for Storage metadata only: DuckLake has no PRIMARY KEY
 * constraint, so the driver records the keys next to the table rather than in DDL.
 */
final class DuckDbTableDefinition implements TableDefinitionInterface
{
    /**
     * @param string[] $primaryKeysNames
     */
    public function __construct(
        private readonly string $schemaName,
        private readonly string $tableName,
        private readonly ColumnCollection $columns,
        private readonly array $primaryKeysNames = [],
        private readonly ?string $description = null,
    ) {
    }

    public function getSchemaName(): string
    {
        return $this->schemaName;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return string[]
     */
    public function getColumnsNames(): array
    {
        $names = [];
        /** @var ColumnInterface $column */
        foreach ($this->columns as $column) {
            $names[] = $column->getColumnName();
        }

        return $names;
    }

    public function getColumnsDefinitions(): ColumnCollection
    {
        return $this->columns;
    }

    /**
     * @return string[]
     */
    public function getPrimaryKeysNames(): array
    {
        return $this->primaryKeysNames;
    }

    /** DuckLake has no temporary tables; a session's scratch space is a DuckDB temp schema. */
    public function isTemporary(): bool
    {
        return false;
    }

    public function getTableType(): TableType
    {
        return TableType::TABLE;
    }
}
