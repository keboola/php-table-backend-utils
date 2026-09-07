<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Table\BigQuery;

use Google\Cloud\BigQuery\Table;
use Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableReflection;
use Keboola\TableBackendUtils\TableWithoutColumnsReflectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BigqueryTableReflectionTest extends TestCase
{
    public function testGetTableDefinitionConvertsEmptyDescriptionToNull(): void
    {
        $reflection = $this->createReflection([
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
        ]);

        self::assertNull($reflection->getTableDefinition()->getDescription());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInfoWithoutColumns(): iterable
    {
        yield 'no schema at all' => [[]];
        yield 'schema without fields' => [['schema' => []]];
        yield 'empty field list' => [['schema' => ['fields' => []]]];
    }

    /**
     * @param array<string, mixed> $info
     */
    #[DataProvider('provideInfoWithoutColumns')]
    public function testColumnsDefinitionsOfTableWithoutColumns(array $info): void
    {
        $reflection = $this->createReflection($info);

        $this->expectException(TableWithoutColumnsReflectionException::class);
        $this->expectExceptionMessage('Table "table" has no columns.');
        $reflection->getColumnsDefinitions();
    }

    /**
     * @param array<string, mixed> $info
     */
    #[DataProvider('provideInfoWithoutColumns')]
    public function testColumnsNamesOfTableWithoutColumns(array $info): void
    {
        $reflection = $this->createReflection($info);

        $this->expectException(TableWithoutColumnsReflectionException::class);
        $this->expectExceptionMessage('Table "table" has no columns.');
        $reflection->getColumnsNames();
    }

    /**
     * @param array<string, mixed> $info what BigQuery reports for the table
     */
    private function createReflection(array $info): BigqueryTableReflection
    {
        $reflection = (new ReflectionClass(BigqueryTableReflection::class))->newInstanceWithoutConstructor();
        $table = new class ($info) extends Table {
            /**
             * @param array<string, mixed> $info
             */
            public function __construct(private readonly array $info)
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
                return $this->info;
            }
        };

        $reflectionClass = new ReflectionClass(BigqueryTableReflection::class);
        $reflectionClass->getProperty('table')->setValue($reflection, $table);
        $reflectionClass->getProperty('datasetName')->setValue($reflection, 'dataset');
        $reflectionClass->getProperty('tableName')->setValue($reflection, 'table');

        return $reflection;
    }
}
