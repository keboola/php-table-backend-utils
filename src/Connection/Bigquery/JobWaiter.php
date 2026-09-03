<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Bigquery;

use Google\Cloud\BigQuery\Job;
use Psr\Log\LoggerInterface;
use Retry\BackOff\BackOffPolicyInterface;
use Throwable;

/**
 * Polls a BigQuery job until it finishes, or until the deadline for waiting passes.
 */
final class JobWaiter
{
    public function __construct(
        private readonly BackOffPolicyInterface $backOffPolicy,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws BigQueryJobTimeoutException
     */
    public function wait(Job $job, ?JobWaitDeadline $deadline): void
    {
        $waitStartedAt = microtime(true);
        $context = $this->backOffPolicy->start();
        do {
            // checked before sleeping: a sleep entered past the deadline is what PHP kills
            if ($deadline !== null && $deadline->hasExpired()) {
                $this->cancel($job);

                throw new BigQueryJobTimeoutException($job->id(), microtime(true) - $waitStartedAt);
            }
            $this->backOffPolicy->backOff($context);
            $job->reload();
        } while (!$job->isComplete());
    }

    /**
     * Best effort: the caller is giving up either way, and a job left running keeps consuming slots
     * and billing for a result nobody will read.
     */
    private function cancel(Job $job): void
    {
        try {
            $job->cancel();
        } catch (Throwable $e) {
            $this->logger->warning('Timed out BigQuery job could not be cancelled.', [
                'exception' => $e,
                'jobId' => $job->id(),
            ]);
        }
    }
}
