<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Bigquery;

/**
 * Wall-clock point after which waiting for a BigQuery job has to be given up.
 */
final class JobWaitDeadline
{
    /**
     * Covers cancelling the job, rendering the error and logging it, and the largest backoff
     * interval a wait can already be sleeping in when the deadline passes.
     */
    public const EXECUTION_TIME_RESERVE_SECONDS = 15.0;

    private function __construct(
        private readonly float $expiresAt,
    ) {
    }

    public static function afterSeconds(float $seconds): self
    {
        return new self(microtime(true) + $seconds);
    }

    /**
     * Whichever comes first: the caller's own budget, or what is left of the process's execution
     * time. A caller asking for 90 seconds when only 40 remain still has to give up after 40.
     *
     * Null when the caller asks for no limit and execution time is unlimited too.
     */
    public static function resolve(
        ?int $maxWaitSeconds,
        float $reserveSeconds = self::EXECUTION_TIME_RESERVE_SECONDS,
    ): ?self {
        $candidates = array_filter([
            $maxWaitSeconds === null ? null : self::afterSeconds((float) $maxWaitSeconds),
            self::fromRemainingExecutionTime($reserveSeconds),
        ]);
        if ($candidates === []) {
            return null;
        }

        $expiresAt = min(array_map(static fn(self $deadline): float => $deadline->expiresAt, $candidates));

        return new self($expiresAt);
    }

    /**
     * Bounded by what PHP itself grants the process: a wait outliving max_execution_time is killed
     * as a fatal error mid-sleep, which reports nothing to the caller and leaves the job running.
     *
     * Null when execution time is unlimited. That covers every CLI command and worker: the CLI
     * SAPI pins max_execution_time to 0 whatever php.ini asks for, so an import keeps waiting
     * for as long as it takes.
     *
     * The elapsed part is measured from the start of the request. set_time_limit() restarts PHP's
     * own timer, so for a caller that raised the limit mid-request this over-estimates how much of
     * the budget is gone and gives up slightly early - the safe direction.
     */
    public static function fromRemainingExecutionTime(
        float $reserveSeconds = self::EXECUTION_TIME_RESERVE_SECONDS,
    ): ?self {
        $limit = (float) ini_get('max_execution_time');
        if ($limit <= 0.0) {
            return null;
        }

        $now = microtime(true);
        $requestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $requestStartedAt = is_numeric($requestTime) ? (float) $requestTime : $now;

        return new self($requestStartedAt + $limit - $reserveSeconds);
    }

    public function hasExpired(): bool
    {
        return microtime(true) >= $this->expiresAt;
    }
}
