<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Connection\Bigquery;

use Keboola\TableBackendUtils\Connection\Bigquery\JobWaitDeadline;
use PHPUnit\Framework\TestCase;

class JobWaitDeadlineTest extends TestCase
{
    private string $originalExecutionTime;

    private mixed $originalRequestTime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalExecutionTime = (string) ini_get('max_execution_time');
        $this->originalRequestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
    }

    protected function tearDown(): void
    {
        ini_set('max_execution_time', $this->originalExecutionTime);
        if ($this->originalRequestTime === null) {
            unset($_SERVER['REQUEST_TIME_FLOAT']);
        } else {
            $_SERVER['REQUEST_TIME_FLOAT'] = $this->originalRequestTime;
        }
        parent::tearDown();
    }

    public function testDeadlineInTheFutureHasNotExpired(): void
    {
        self::assertFalse(JobWaitDeadline::afterSeconds(60.0)->hasExpired());
    }

    public function testDeadlineInThePastHasExpired(): void
    {
        self::assertTrue(JobWaitDeadline::afterSeconds(-1.0)->hasExpired());
    }

    public function testUnlimitedExecutionTimeHasNoDeadline(): void
    {
        ini_set('max_execution_time', '0');

        self::assertNull(JobWaitDeadline::fromRemainingExecutionTime());
        self::assertNull(JobWaitDeadline::resolve(null));
    }

    public function testUnlimitedExecutionTimeStillHonoursCallerBudget(): void
    {
        ini_set('max_execution_time', '0');

        $deadline = JobWaitDeadline::resolve(-1);
        self::assertNotNull($deadline);
        self::assertTrue($deadline->hasExpired());
    }

    public function testSpentExecutionTimeYieldsExpiredDeadline(): void
    {
        ini_set('max_execution_time', '300');
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 290.0;

        $deadline = JobWaitDeadline::fromRemainingExecutionTime();
        self::assertNotNull($deadline);
        self::assertTrue($deadline->hasExpired());
    }

    public function testExecutionTimeReserveIsSubtracted(): void
    {
        ini_set('max_execution_time', '300');
        // 288s spent leaves 12s, which is less than the 15s reserve
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 288.0;

        $deadline = JobWaitDeadline::fromRemainingExecutionTime();
        self::assertNotNull($deadline);
        self::assertTrue($deadline->hasExpired());

        $withoutReserve = JobWaitDeadline::fromRemainingExecutionTime(0.0);
        self::assertNotNull($withoutReserve);
        self::assertFalse($withoutReserve->hasExpired());
    }

    public function testResolveGivesUpWhenExecutionTimeRunsOutBeforeCallerBudget(): void
    {
        ini_set('max_execution_time', '300');
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 290.0;

        // the caller asks for 90s more, but the request cannot afford any of it
        $deadline = JobWaitDeadline::resolve(90);
        self::assertNotNull($deadline);
        self::assertTrue($deadline->hasExpired());
    }

    public function testResolveGivesUpWhenCallerBudgetRunsOutBeforeExecutionTime(): void
    {
        ini_set('max_execution_time', '300');
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        $deadline = JobWaitDeadline::resolve(-1);
        self::assertNotNull($deadline);
        self::assertTrue($deadline->hasExpired());
    }

    public function testResolveKeepsWaitingWhileBothBudgetsHold(): void
    {
        ini_set('max_execution_time', '300');
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        $deadline = JobWaitDeadline::resolve(90);
        self::assertNotNull($deadline);
        self::assertFalse($deadline->hasExpired());
    }
}
