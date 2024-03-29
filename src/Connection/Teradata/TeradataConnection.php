<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Teradata;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Exception;
use Throwable;

class TeradataConnection
{
    /**
     * @param array{
     *     host:string,
     *     user:string,
     *     password:string,
     *     port:int,
     *     dbname:?string,
     * } $params
     * @throws Exception
     */
    public static function getConnection(array $params, ?Configuration $config = null): Connection
    {
        $params = array_merge($params, [
            'driverClass' => TeradataDriver::class,
        ]);

        try {
            return DriverManager::getConnection($params, $config);
        } catch (Throwable $e) {
            throw new Exception($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
