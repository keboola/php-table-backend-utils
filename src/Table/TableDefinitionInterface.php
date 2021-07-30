<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Table;

use Keboola\TableBackendUtils\Column\ColumnCollection;

interface TableDefinitionInterface
{
    public function getTableName(): string;

    public function getSchemaName(): string;

    /**
     * @return string[]
     */
    public function getColumnsNames(): array;

    public function getColumnsDefinitions(): ColumnCollection;

    /**
     * @return string[]
     */
    public function getPrimaryKeysNames(): array;

    public function isTemporary(): bool;
}
