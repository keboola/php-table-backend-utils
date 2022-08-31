<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Schema;

use Keboola\TableBackendUtils\Escaping\SynapseQuote;
use Keboola\TableBackendUtils\Utils\CaseConverter;

class SynapseSchemaQueryBuilder
{
    public function getCreateSchemaCommand(string $schemaName): string
    {
        $schemaName = CaseConverter::stringToUpper($schemaName);
        return sprintf('CREATE SCHEMA %s', SynapseQuote::quoteSingleIdentifier($schemaName));
    }

    public function getDropSchemaCommand(string $schemaName): string
    {
        $schemaName = CaseConverter::stringToUpper($schemaName);
        return sprintf('DROP SCHEMA %s', SynapseQuote::quoteSingleIdentifier($schemaName));
    }
}
