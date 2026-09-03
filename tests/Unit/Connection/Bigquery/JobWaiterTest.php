<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Connection\Bigquery;

use Google\Cloud\BigQuery\Job;
use Keboola\TableBackendUtils\Connection\Bigquery\BigQueryJobTimeoutException;
use Keboola\TableBackendUtils\Connection\Bigquery\JobWaiter;
use Keboola\TableBackendUtils\Connection\Bigquery\JobWaitDeadline;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Retry\BackOff\NoBackOffPolicy;
use RuntimeException;

class JobWaiterTest extends TestCase
{
    public function testWaitsUntilJobIsComplete(): void
    {
        $job = $this->createJobMock();
        $job->expects(self::exactly(3))->method('reload');
        $job->method('isComplete')->willReturn(false, false, true);
        $job->expects(self::never())->method('cancel');

        $this->createWaiter()->wait($job, JobWaitDeadline::afterSeconds(60.0));
    }

    public function testNoDeadlineWaitsUntilJobIsComplete(): void
    {
        $job = $this->createJobMock();
        $job->expects(self::once())->method('reload');
        $job->method('isComplete')->willReturn(true);
        $job->expects(self::never())->method('cancel');

        $this->createWaiter()->wait($job, null);
    }

    public function testExpiredDeadlineCancelsJobAndThrows(): void
    {
        $job = $this->createJobMock();
        $job->expects(self::never())->method('reload');
        $job->method('isComplete')->willReturn(false);
        $job->expects(self::once())->method('cancel');

        try {
            $this->createWaiter()->wait($job, JobWaitDeadline::afterSeconds(-1.0));
            self::fail(sprintf('Expected %s to be thrown.', BigQueryJobTimeoutException::class));
        } catch (BigQueryJobTimeoutException $e) {
            self::assertSame('job-id', $e->getJobId());
            self::assertStringContainsString('job-id', $e->getMessage());
            self::assertStringContainsString('was cancelled', $e->getMessage());
        }
    }

    public function testDeadlineReachedWhileJobIsStillRunningCancelsJobAndThrows(): void
    {
        $job = $this->createJobMock();
        $job->method('isComplete')->willReturn(false);
        $job->expects(self::once())->method('cancel');

        // expires between the first and the second poll
        $deadline = JobWaitDeadline::afterSeconds(0.05);
        $job->expects(self::once())->method('reload')->willReturnCallback(static function () use ($deadline): void {
            usleep(100_000);
            self::assertTrue($deadline->hasExpired());
        });

        $this->expectException(BigQueryJobTimeoutException::class);
        $this->createWaiter()->wait($job, $deadline);
    }

    public function testFailedCancellationIsLoggedAndDoesNotMaskTheTimeout(): void
    {
        $job = $this->createJobMock();
        $job->method('isComplete')->willReturn(false);
        $job->expects(self::once())->method('cancel')->willThrowException(new RuntimeException('cancel failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Timed out BigQuery job could not be cancelled.',
                self::callback(static fn(array $context): bool => $context['jobId'] === 'job-id'
                    && $context['exception'] instanceof RuntimeException),
            );

        $this->expectException(BigQueryJobTimeoutException::class);
        (new JobWaiter(new NoBackOffPolicy(), $logger))
            ->wait($job, JobWaitDeadline::afterSeconds(-1.0));
    }

    private function createWaiter(): JobWaiter
    {
        return new JobWaiter(new NoBackOffPolicy(), new NullLogger());
    }

    /**
     * @return Job&MockObject
     */
    private function createJobMock(): Job
    {
        $job = $this->createMock(Job::class);
        $job->method('id')->willReturn('job-id');

        return $job;
    }
}
