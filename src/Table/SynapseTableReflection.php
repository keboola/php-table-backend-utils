<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Table;

use Doctrine\DBAL\Connection;
use Keboola\TableBackendUtils\Auth\Grant\Synapse\Permission;
use Keboola\TableBackendUtils\Column\ColumnCollection;
use Keboola\TableBackendUtils\Column\SynapseColumn;
use Keboola\TableBackendUtils\Escaping\SynapseQuote;
use Keboola\TableBackendUtils\ReflectionException;
use Keboola\TableBackendUtils\Table\Synapse\TableDistributionDefinition;
use Keboola\TableBackendUtils\Table\Synapse\TableIndexDefinition;
use Keboola\TableBackendUtils\TableNotExistsReflectionException;
use LogicException;
use function Keboola\Utils\returnBytes;

final class SynapseTableReflection implements TableReflectionInterface
{
    /** @var Connection */
    private $connection;

    /** @var string */
    private $schemaName;

    /** @var string */
    private $tableName;

    /** @var string */
    private $objectId;

    /** @var bool */
    private $isTemporary;

    public function __construct(Connection $connection, string $schemaName, string $tableName)
    {
        // temporary tables starts with #
        $this->isTemporary = strpos($tableName, '#') === 0;
        $this->tableName = $tableName;
        $this->schemaName = $schemaName;
        $this->connection = $connection;
    }

    /**
     * @return string[]
     */
    public function getColumnsNames(): array
    {
        if ($this->isTemporary) {
            $this->throwTemporaryTableException();
        }
        $tableId = $this->getObjectId();

        $columns = $this->connection->fetchAll(sprintf(
            'SELECT [name] FROM [sys].[columns] WHERE [object_id] = %s ORDER BY [column_id]',
            SynapseQuote::quote($tableId)
        ));

        return array_map(static function ($column) {
            return $column['name'];
        }, $columns);
    }

    /**
     * tempdb is not implemented in synapse and information about tables cannot be obtained
     */
    private function throwTemporaryTableException(): void
    {
        throw new ReflectionException('Temporary tables cannot be reflected in Synapse.');
    }

    public function getObjectId(): string
    {
        if ($this->objectId !== null) {
            return $this->objectId;
        }

        if ($this->isTemporary) {
            $object = SynapseQuote::quote(
                'tempdb..'
                . SynapseQuote::quoteSingleIdentifier($this->tableName)
            );
        } else {
            $object = SynapseQuote::quote(
                SynapseQuote::quoteSingleIdentifier($this->schemaName)
                . '.' .
                SynapseQuote::quoteSingleIdentifier($this->tableName)
            );
        }

        /** @var string|null $objectId */
        $objectId = $this->connection->fetchColumn(sprintf(
            'SELECT OBJECT_ID(N%s)',
            $object
        ));

        if ($objectId === null) {
            throw new TableNotExistsReflectionException(sprintf(
                'Table "%s.%s" does not exist.',
                $this->schemaName,
                $this->tableName
            ));
        }

        $this->objectId = $objectId;
        return $this->objectId;
    }

    public function getColumnsDefinitions(): ColumnCollection
    {
        if ($this->isTemporary) {
            $this->throwTemporaryTableException();
        }

        $tableId = $this->getObjectId();

        $sql = <<<EOT
SELECT 
    c.name AS column_name,
    c.precision AS column_precision,
    c.scale AS column_scale,
    c.max_length AS column_length,
    c.is_nullable AS column_is_nullable,
    d.definition AS column_default,
    t.name AS column_type
FROM sys.columns AS c
JOIN sys.types AS t ON t.user_type_id = c.user_type_id
LEFT JOIN sys.default_constraints AS d ON c.default_object_id = d.object_id
WHERE c.object_id = '$tableId'
ORDER BY c.column_id
;
EOT;

        /** @var array{
         *     column_name:string,
         *     column_type:string,
         *     column_length:string,
         *     column_precision:string,
         *     column_scale:string,
         *     column_is_nullable:string,
         *     column_default:?string
         * }[] $columns */
        $columns = $this->connection->fetchAll($sql);

        $columns = array_map(static function ($col) {
            return SynapseColumn::createFromDB($col);
        }, $columns);

        return new ColumnCollection($columns);
    }

    public function getRowsCount(): int
    {
        $count = $this->connection->fetchColumn(sprintf(
            'SELECT COUNT_BIG(*) AS [count] FROM %s.%s',
            SynapseQuote::quoteSingleIdentifier($this->schemaName),
            SynapseQuote::quoteSingleIdentifier($this->tableName)
        ));

        return (int) $count;
    }

    /**
     * @return string[]
     */
    public function getPrimaryKeysNames(): array
    {
        if ($this->isTemporary) {
            $this->throwTemporaryTableException();
        }

        $tableId = $this->getObjectId();

        $result = $this->connection->fetchAll(
            <<< EOT
SELECT COL_NAME(ic.OBJECT_ID,ic.column_id) AS column_name
    FROM sys.indexes AS i INNER JOIN
        sys.index_columns AS ic ON i.OBJECT_ID = ic.OBJECT_ID AND i.index_id = ic.index_id
    WHERE i.is_primary_key = 1 AND i.OBJECT_ID = '$tableId'
    ORDER BY ic.index_column_id
EOT
        );

        return array_map(static function ($item) {
            return $item['column_name'];
        }, $result);
    }

    public function getTableStats(): TableStatsInterface
    {
        if ($this->isTemporary) {
            $this->throwTemporaryTableException();
        }

        /**
         * @var array{
         *  name: string,
         *  rows: string,
         *  reserved: string,
         *  data: string,
         *  index_size: string,
         *  unused: string,
         * } $info
         */
        $info = $this->connection->fetchAssoc(sprintf(
            'EXEC sp_spaceused \'%s.%s\'',
            SynapseQuote::quoteSingleIdentifier($this->schemaName),
            SynapseQuote::quoteSingleIdentifier($this->tableName)
        ));

        return new TableStats(
            (int) returnBytes(
                // removes all whitespaces and unit(bytes)
                preg_replace('/[B\s]+/ui', '', $info['data'])
            ),
            (int) $info['rows']
        );
    }

    public function isTemporary(): bool
    {
        return $this->isTemporary;
    }

    /**
     * @return array{
     *  schema_name: string,
     *  name: string
     * }[]
     */
    public function getDependentViews(): array
    {
        $sql = 'SELECT * FROM INFORMATION_SCHEMA.VIEWS';
        $views = $this->connection->fetchAll($sql);

        $objectNameWithSchema = sprintf(
            '%s.%s',
            SynapseQuote::quoteSingleIdentifier($this->schemaName),
            SynapseQuote::quoteSingleIdentifier($this->tableName)
        );

        /**
         * @var array{
         *  schema_name: string,
         *  name: string
         * }[] $dependencies
         */
        $dependencies = [];
        foreach ($views as $view) {
            if ($view['VIEW_DEFINITION'] === null
                || strpos($view['VIEW_DEFINITION'], $objectNameWithSchema) === false
            ) {
                continue;
            }

            $dependencies[] = [
                'schema_name' => $view['TABLE_SCHEMA'],
                'name' => $view['TABLE_NAME'],
            ];
        }

        return $dependencies;
    }

    /**
     * @return TableDistributionDefinition::TABLE_DISTRIBUTION_*
     */
    public function getTableDistribution(): string
    {
        $tableId = $this->getObjectId();

        return $this->connection->fetchColumn(
            <<< EOT
SELECT distribution_policy_desc
    FROM sys.pdw_table_distribution_properties AS dp
    WHERE dp.OBJECT_ID = '$tableId'
EOT
        );
    }

    /**
     * @return string[]
     */
    public function getTableDistributionColumnsNames(): array
    {
        $tableId = $this->getObjectId();

        $result = $this->connection->fetchAll(
            <<< EOT
SELECT c.name
FROM sys.pdw_column_distribution_properties AS dp
     INNER JOIN sys.columns AS c ON dp.column_id = c.column_id
WHERE dp.distribution_ordinal = 1 AND dp.OBJECT_ID = '$tableId' AND c.object_id = '$tableId'
EOT
        );

        return array_map(static function ($item) {
            return $item['name'];
        }, $result);
    }

    /**
     * @return SynapseTableDefinition
     */
    public function getTableDefinition(): TableDefinitionInterface
    {
        return new SynapseTableDefinition(
            $this->schemaName,
            $this->tableName,
            $this->isTemporary(),
            $this->getColumnsDefinitions(),
            $this->getPrimaryKeysNames(),
            new TableDistributionDefinition(
                $this->getTableDistribution(),
                $this->getTableDistributionColumnsNames()
            ),
            new TableIndexDefinition(
                $this->getTableIndex(),
                $this->getTableIndexColumnsNames()
            )
        );
    }

    /**
     * @return TableIndexDefinition::TABLE_INDEX_TYPE_*
     */
    public function getTableIndex(): string
    {
        $tableId = $this->getObjectId();
        $result = $this->connection->fetchFirstColumn(
            <<< EOT
        select i.type_desc from sys.tables t
JOIN sys.indexes i
    ON i.object_id = t.object_id
JOIN sys.schemas s
    ON t.schema_id = s.schema_id
FULL OUTER JOIN sys.pdw_table_distribution_properties dp
    ON t.object_id = dp.object_id
WHERE t.object_id = '$tableId'
EOT
        );

        $indexType = $result[0];

        // Synapse sys.indexes has indexes without INDEX suffix
        switch ($indexType) {
            case 'CLUSTERED COLUMNSTORE':
                return TableIndexDefinition::TABLE_INDEX_TYPE_CLUSTERED_COLUMNSTORE_INDEX;
            case 'CLUSTERED':
                return TableIndexDefinition::TABLE_INDEX_TYPE_CLUSTERED_INDEX;
            case TableIndexDefinition::TABLE_INDEX_TYPE_HEAP:
                return TableIndexDefinition::TABLE_INDEX_TYPE_HEAP;
        }

        throw new LogicException(sprintf(
            'Unknown index type for Synapse "%s"',
            $indexType
        ));
    }

    /**
     * @return string[]
     */
    public function getTableIndexColumnsNames(): array
    {
        $tableId = $this->getObjectId();
        $result = $this->connection->fetchFirstColumn(
            <<< EOT
SELECT
    c.Name
FROM
    sys.tables t
INNER JOIN 
    sys.indexes i ON t.object_id = i.object_id
INNER JOIN 
    sys.index_columns ic ON i.index_id = ic.index_id AND i.object_id = ic.object_id
INNER JOIN 
    sys.columns c ON ic.column_id = c.column_id AND ic.object_id = c.object_id
WHERE
    t.object_id = '$tableId'
    AND
    i.index_id = 1  -- clustered index
    AND
    i.type = 1 -- clustered index type, vs. heap and CCI and others
    AND c.is_identity = 0
    AND EXISTS (SELECT * 
                FROM sys.columns c2 
                WHERE ic.object_id = c2.object_id )
EOT
        );

        if (count($result) === 0) {
            return [];
        }

        return [$result[0]];
    }

    /**
     * @param array<Permission::GRANT_*> $showOnlyGrants
     * @param array<Permission::GRANT_*> $showOnlyForGrantedObjectType
     * @return array<int, array{'name': string, 'grant':string}>
     */
    public function whoHasGrantOnTable(
        array $showOnlyGrants = [],
        array $showOnlyForGrantedObjectType = []
    ): array {
        $limitWhere = '';
        if (count($showOnlyGrants) !== 0) {
            $grantsEscaped = [];
            foreach ($showOnlyGrants as $grant) {
                $grantsEscaped[] = sprintf('N\'%s\'', $grant);
            }
            $limitWhere .= sprintf(' AND pe.permission_name IN (%s)', implode(',', $grantsEscaped));
        }
        if (count($showOnlyForGrantedObjectType) !== 0) {
            $objectsEscaped = [];
            foreach ($showOnlyForGrantedObjectType as $object) {
                $objectsEscaped[] = sprintf('N\'%s\'', $object);
            }
            $limitWhere .= sprintf(' AND pr.type_desc IN (%s)', implode(',', $objectsEscaped));
        }

        $sql = sprintf(
            <<<SQL
SELECT pr.name AS [NAME], pe.permission_name AS [GRANT]
FROM sys.database_principals AS pr  
JOIN sys.database_permissions AS pe 
    ON pe.grantee_principal_id = pr.principal_id
WHERE pe.major_id = %s
%s
;
SQL
            ,
            $this->getObjectId(),
            $limitWhere
        );

        /** @var array<int, array{'name': string, 'grant':string}> $result */
        $result = $this->connection->fetchAllAssociative($sql);
        return $result;
    }
}
