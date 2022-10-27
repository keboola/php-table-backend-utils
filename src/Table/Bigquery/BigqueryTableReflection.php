<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Table\Bigquery;

use Google\Cloud\BigQuery\BigQueryClient;
use Keboola\TableBackendUtils\Column\Bigquery\BigqueryColumn;
use Keboola\TableBackendUtils\Column\ColumnCollection;
use Keboola\TableBackendUtils\Escaping\Bigquery\BigqueryQuote;
use Keboola\TableBackendUtils\Table\TableDefinitionInterface;
use Keboola\TableBackendUtils\Table\TableReflectionInterface;
use Keboola\TableBackendUtils\Table\TableStatsInterface;
use LogicException;

class BigqueryTableReflection implements TableReflectionInterface
{
    private BigQueryClient $bqClient;

    private string $datasetName;

    private string $tableName;

    public function __construct(BigQueryClient $bqClient, string $datasetName, string $tableName)
    {
        $this->tableName = $tableName;
        $this->datasetName = $datasetName;
        $this->bqClient = $bqClient;
    }

    /** @return  string[] */
    public function getColumnsNames(): array
    {
        $query = $this->bqClient->query(
            sprintf(
                'SELECT column_name FROM %s.INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s',
                BigqueryQuote::quoteSingleIdentifier($this->datasetName),
                BigqueryQuote::quote($this->tableName)
            )
        );
        $queryResults = $this->bqClient->runQuery($query);

        $columns = [];
        /**
         * @var array{
         *  table_catalog: string,
         *  table_schema: string,
         *  table_name: string,
         *  column_name: string,
         *  ordinal_position: int,
         *  is_nullable: string,
         *  data_type: string,
         *  is_hidden: string,
         *  is_system_defined: string,
         *  is_partitioning_column: string,
         *  clustering_ordinal_position: ?string,
         *  collation_name: string,
         *  column_default: string,
         *  rounding_mode: ?string,
         * } $row
         */
        foreach ($queryResults as $row) {
            $columns[] = $row['column_name'];
        }
        return $columns;
    }

    public function getColumnsDefinitions(): ColumnCollection
    {
        $query = $this->bqClient->query(
            sprintf(
                'SELECT * EXCEPT(is_generated, generation_expression, is_stored, is_updatable) 
FROM %s.INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s',
                BigqueryQuote::quoteSingleIdentifier($this->datasetName),
                BigqueryQuote::quote($this->tableName)
            )
        );

        $queryResults = $this->bqClient->runQuery($query);

        $columns = [];
        /**
         * @var array{
         *  table_catalog: string,
         *  table_schema: string,
         *  table_name: string,
         *  column_name: string,
         *  ordinal_position: int,
         *  is_nullable: string,
         *  data_type: string,
         *  is_hidden: string,
         *  is_system_defined: string,
         *  is_partitioning_column: string,
         *  clustering_ordinal_position: ?string,
         *  collation_name: string,
         *  column_default: string,
         *  rounding_mode: ?string,
         * } $row
         */
        foreach ($queryResults as $row) {
            $columns[] = BigqueryColumn::createFromDB($row);
        }

        return new ColumnCollection($columns);
    }

    public function getRowsCount(): int
    {
        throw new LogicException('Not implemented');
    }

    /** @return  array<string> */
    public function getPrimaryKeysNames(): array
    {
        throw new LogicException('Not implemented');
    }

    public function getTableStats(): TableStatsInterface
    {
        throw new LogicException('Not implemented');
    }

    public function isTemporary(): bool
    {
        // TODO: Implement getDependentViews() method.
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     * array{
     *  schema_name: string,
     *  name: string
     * }[]
     */
    public function getDependentViews(): array
    {
        throw new LogicException('Not implemented');
    }

    public function getTableDefinition(): TableDefinitionInterface
    {
        throw new LogicException('Not implemented');
    }

    public function exists(): bool
    {
        throw new LogicException('Not implemented');
    }
}
