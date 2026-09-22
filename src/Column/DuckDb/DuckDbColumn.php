<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Column\DuckDb;

use Keboola\Datatype\Definition\DefinitionInterface;
use Keboola\Datatype\Definition\DuckDb;
use Keboola\TableBackendUtils\Column\ColumnInterface;

final class DuckDbColumn implements ColumnInterface
{
    public function __construct(
        private readonly string $columnName,
        private readonly DuckDb $columnDefinition,
    ) {
    }

    /**
     * The non-typed column shape: a length-less VARCHAR that cannot be null. Unlike
     * Snowflake's, it carries no DEFAULT — DuckLake rejects a DEFAULT on a CREATE TABLE
     * inside its catalog, so the empty string is applied by the import instead.
     */
    public static function createGenericColumn(string $columnName): self
    {
        return new self(
            $columnName,
            new DuckDb(DuckDb::TYPE_VARCHAR, ['nullable' => false]),
        );
    }

    public function getColumnName(): string
    {
        return $this->columnName;
    }

    public function getDescription(): ?string
    {
        return $this->columnDefinition->getDescription();
    }

    /**
     * @return DuckDb
     */
    public function getColumnDefinition(): DefinitionInterface
    {
        return $this->columnDefinition;
    }

    /**
     * @param array{
     *     column_name: string,
     *     column_type: string,
     *     "null": string,
     * } $dbResponse row of DuckDB's `DESCRIBE`
     */
    public static function createFromDB(array $dbResponse): self
    {
        $type = $dbResponse['column_type'];
        $length = null;

        $matches = [];
        if (preg_match('/^(?<type>[A-Z ]+)\((?<length>[\d, ]+)\)$/ui', $type, $matches) === 1) {
            $type = trim($matches['type']);
            $length = $matches['length'];
        }

        return new self($dbResponse['column_name'], new DuckDb($type, [
            'nullable' => strtoupper($dbResponse['null']) === 'YES',
            'length' => $length,
        ]));
    }

    public static function createTimestampColumn(string $columnName = self::TIMESTAMP_COLUMN_NAME): self
    {
        return new self($columnName, new DuckDb(DuckDb::TYPE_TIMESTAMP));
    }
}
