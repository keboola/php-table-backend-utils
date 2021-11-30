<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Snowflake;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\ParameterType;
use Exception;
use Keboola\TableBackendUtils\Connection\Snowflake\Exception\DriverException;
use Keboola\TableBackendUtils\Escaping\Snowflake\SnowflakeQuote;

class SnowflakeConnection implements Connection
{
    /** @var resource */
    private $conn;

    /**
     * @param mixed[]|null $options
     */
    public function __construct(
        string $dsn,
        string $user,
        string $password,
        ?array $options
    ) {
        try {
            $handle = odbc_connect($dsn, $user, $password);
            assert($handle !== false);
            $this->conn = $handle;
        } catch (\Throwable $e) {
            throw DriverException::newConnectionFailure($e->getMessage(), (int) $e->getCode(), $e->getPrevious());
        }

        if (isset($options['runId'])) {
            $queryTag = [
                'runId' => $options['runId'],
            ];
            $this->query("ALTER SESSION SET QUERY_TAG='" . json_encode($queryTag) . "';");
        }
    }

    public function query(string $sql): \Doctrine\DBAL\Driver\Result
    {
        $stmt = $this->prepare($sql);
        return $stmt->execute();
    }

    public function prepare(string $sql): SnowflakeStatement
    {
        return new SnowflakeStatement($this->conn, $sql);
    }

    /**
     * @inheritDoc
     */
    public function quote($value, $type = ParameterType::STRING): string
    {
        return SnowflakeQuote::quote($value);
    }

    public function exec(string $sql): int
    {
        $stmt = $this->prepare($sql);
        $result = $stmt->execute();

        return $result->rowCount();
    }

    /**
     * @inheritDoc
     */
    public function lastInsertId($name = null)
    {
        // TODO: Implement lastInsertId() method.
        throw new Exception('method is not implemented yet');
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction()
    {
        if ($this->inTransaction()) {
            throw new DriverException('Transaction was already started');
        }
        return odbc_autocommit($this->conn, false);
    }


    private function inTransaction(): bool
    {
        return !odbc_autocommit($this->conn);
    }

    public function commit(): bool
    {
        if (!$this->inTransaction()) {
            throw new DriverException('Transaction was not started');
        }
        return odbc_commit($this->conn) && odbc_autocommit($this->conn, true);
    }

    public function rollBack(): bool
    {
        if (!$this->inTransaction()) {
            throw new DriverException('Transaction was not started');
        }
        return odbc_rollback($this->conn) && odbc_autocommit($this->conn, true);
    }

    public function errorCode(): ?string
    {
        return odbc_error($this->conn);
    }

    /**
     * @inheritDoc
     * @return array{code:string, message:string}
     */
    public function errorInfo(): array
    {
        return [
            'code' => odbc_error($this->conn),
            'message' => odbc_errormsg($this->conn),
        ];
    }

    public function __destruct()
    {
        if (is_resource($this->conn)) {
            odbc_close($this->conn);
        }
    }
}
