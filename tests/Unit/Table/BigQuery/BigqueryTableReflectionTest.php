<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Table\BigQuery;

use Google\Cloud\BigQuery\Table;
use Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableReflection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BigqueryTableReflectionTest extends TestCase
{
    public function testGetTableDefinitionConvertsEmptyDescriptionToNull(): void
    {
        $reflection = (new ReflectionClass(BigqueryTableReflection::class))->newInstanceWithoutConstructor();
        $table = new class extends Table {
            public function __construct()
            {
            }

            public function exists(): bool
            {
                return true;
            }

            /**
             * @param array<mixed> $options
             * @return array<string, mixed>
             */
            public function info(array $options = []): array
            {
                return [
                    'description' => '',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'id',
                                'type' => 'STRING',
                                'mode' => 'NULLABLE',
                            ],
                        ],
                    ],
                ];
            }
        };

        $reflectionClass = new ReflectionClass(BigqueryTableReflection::class);
        $reflectionClass->getProperty('table')->setValue($reflection, $table);
        $reflectionClass->getProperty('datasetName')->setValue($reflection, 'dataset');
        $reflectionClass->getProperty('tableName')->setValue($reflection, 'table');

        self::assertNull($reflection->getTableDefinition()->getDescription());
    }
}
