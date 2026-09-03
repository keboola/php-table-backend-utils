<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Bigquery;

use Keboola\CommonExceptions\UserExceptionInterface;
use RuntimeException;

/**
 * The job was still running when the caller's wait budget ran out. The job is cancelled before this
 * is thrown, so nothing keeps running behind the failed call.
 */
class BigQueryJobTimeoutException extends RuntimeException implements UserExceptionInterface
{
    public function __construct(
        private readonly string $jobId,
        private readonly float $waitedSeconds,
    ) {
        parent::__construct(sprintf(
            'BigQuery job "%s" did not finish within %.1f seconds and was cancelled.',
            $jobId,
            $waitedSeconds,
        ));
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getWaitedSeconds(): float
    {
        return $this->waitedSeconds;
    }
}
