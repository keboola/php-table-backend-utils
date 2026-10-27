<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Snowflake;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Keboola\TableBackendUtils\Connection\Exception\DriverException;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\BackOff\ExponentialRandomBackOffPolicy;
use Retry\BackOff\UniformRandomBackOffPolicy;
use Retry\Policy\CallableRetryPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Throwable;

class SnowflakeStatement implements Statement
{
    /** @var resource */
    private $dbh;

    private RetryProxy $retry;

    /** @var resource|null */
    private $stmt = null;

    /**
     * Prepared once, reused by every parameterized execute().
     *
     * @var resource|null
     */
    private $preparedStmt = null;

    /** @var array<mixed> */
    private array $params = [];

    private string $query;

    /**
     * @param resource $dbh database handle
     */
    public function __construct($dbh, string $query, RetryProxy|null $retry = null)
    {
        if ($retry === null) {
            $this->retry = new RetryProxy(
                new CallableRetryPolicy(
                    // static: a closure bound to $this would close a reference cycle through
                    // $this->retry, keeping the ODBC result and its column buffers (up to the
                    // declared VARCHAR size each) alive until the cycle collector runs
                    static function (Throwable $e): bool {
                        if (str_contains($e->getMessage(), 'SYSTEM$ALLOWLIST')) {
                            // Retry in case of SYSTEM$ALLOWLIST error #prod_24_7___inc_25140
                            // this is usually accompanied with SNFLK incidents
                            // or can happen in case hostname is wrong
                            return true;
                        }
                        return false;
                    },
                    5, // 5 attempts
                ),
                new UniformRandomBackOffPolicy(
                    3_000, // 3 seconds
                    10_000, // 10 seconds
                ),
            );
        } else {
            $this->retry = $retry;
        }
        $this->dbh = $dbh;
        $this->query = $query;
    }

    /**
     * @return resource
     */
    private function prepare()
    {
        /** @var resource|false $stmt */
        $stmt = $this->retry->call(function () {
            return @odbc_prepare($this->dbh, $this->query);
        });
        if (!$stmt) {
            throw DriverException::newFromHandle($this->dbh);
        }
        return $stmt;
    }

    /**
     * @inheritDoc
     */
    public function bindValue($param, $value, $type = ParameterType::STRING): bool
    {
        return $this->bindParam($param, $value, $type);
    }

    /**
     * @inheritDoc
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): bool
    {
        $this->params[$param] = &$variable;
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute($params = null): Result
    {
        if (!empty($params) && is_array($params)) { // @phpstan-ignore function.alreadyNarrowedType
            foreach ($params as $pos => $value) {
                if (is_int($pos)) {
                    $pos += 1;
                }
                $this->bindValue($pos, $value);
            }
        }

        try {
            $this->retry->call(function (): void {
                $this->stmt = $this->params === []
                    ? $this->executeDirect()
                    : $this->prepareAndExecute();
            });
        } catch (Throwable $e) {
            if ($e instanceof DriverException) {
                throw $e;
            }
            throw DriverException::newFromHandle($this->dbh);
        }

        assert($this->stmt !== null);
        return new Result($this->stmt);
    }

    /**
     * @return resource
     */
    private function executeDirect()
    {
        /** @var resource|false $stmt */
        $stmt = @odbc_exec($this->dbh, $this->query);
        if (!$stmt) {
            throw DriverException::newFromHandle($this->dbh);
        }
        return $stmt;
    }

    /**
     * @return resource
     */
    private function prepareAndExecute()
    {
        $this->preparedStmt ??= $this->prepare();
        $stmt = $this->preparedStmt;
        $bind = $this->repairBinding($this->params);
        if ($this->ignoringNoDataWarning(static fn(): bool => odbc_execute($stmt, $bind)) === false) {
            throw DriverException::newFromHandle($this->dbh);
        }
        return $stmt;
    }

    /**
     * ODBC 4.x returns SQL_NO_DATA for DML that affects no rows; odbc_execute() then
     * succeeds but still raises an E_WARNING, which error handlers turn into an exception.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function ignoringNoDataWarning(callable $call): mixed
    {
        $previous = null;
        $previous = set_error_handler(
            static function (
                int $errno,
                string $errstr,
                string $errfile = '',
                int $errline = 0,
            ) use (&$previous): bool {
                if (str_ends_with($errstr, 'SQL state 02000 in SQLExecute')) {
                    return true;
                }
                if ($previous === null) {
                    return false;
                }
                return (bool) $previous($errno, $errstr, $errfile, $errline);
            },
        );
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Avoid odbc file open http://php.net/manual/en/function.odbc-execute.php
     *
     * @param array<mixed> $bind
     * @return array<mixed>
     */
    private function repairBinding(array $bind): array
    {
        return array_map(function ($value) {
            if (!is_string($value)) {
                return $value;
            }
            if (preg_match("/^'.*'$/", $value)) {
                return " {$value} ";
            }

            return $value;
        }, $bind);
    }
}
