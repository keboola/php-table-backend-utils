<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils;

class TableWithoutColumnsReflectionException extends ReflectionException
{
    public static function createForTable(string $tableName): self
    {
        return new self(sprintf('Table "%s" has no columns.', $tableName));
    }
}
