<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Connection\Bigquery;

use Exception;
use Generator;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Utils;
use Keboola\TableBackendUtils\Connection\Bigquery\Retry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Throwable;

class RetryTest extends TestCase
{
    /** Mirrors Retry::MAX_UNAUTHORIZED_RETRIES; the first attempt number that must no longer retry. */
    private const UNAUTHORIZED_RETRY_CAP = 6;

    private function getException(int $code, string $message = ''): Throwable
    {
        return new Exception($message, $code);
    }

    private function getRequestException(int $code, ?string $message = ''): Throwable
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getBody')->willReturn(Utils::streamFor($message));
        return new BadResponseException(
            '',
            $this->createStub(RequestInterface::class),
            $response,
            $this->getException($code),
        );
    }

    /**
     * @return int[][]
     */
    public static function retryCodesProvider(): array
    {
        return [
            [200],
            [210],
            [299],
        ];
    }

    #[DataProvider('retryCodesProvider')]
    public function testSuccessResponse(int $statusCode): void
    {
        $fn = Retry::getRetryDecider(new NullLogger());
        $ex = $this->getException($statusCode);
        $this->assertFalse($fn($ex));
    }

    /**
     * @return int[][]
     */
    public static function retryCodesErrorProvider(): array
    {
        return [
            [429],
            [500],
            [503],
        ];
    }

    #[DataProvider('retryCodesErrorProvider')]
    public function testRetryOnCodesResponse(int $statusCode): void
    {
        $fn = Retry::getRetryDecider(new NullLogger());
        $ex = $this->getException($statusCode);
        $this->assertTrue($fn($ex));
    }

    public function testNotJsonResponse(): void
    {
        $fn = Retry::getRetryDecider(new NullLogger());
        $ex = $this->getException(418, 'not json');
        $this->assertFalse($fn($ex));
    }

    public function testNotExpectedContentResponse(): void
    {
        $fn = Retry::getRetryDecider(new NullLogger());
        $ex = $this->getException(418, '{"data" : "test"}');
        $this->assertFalse($fn($ex));
    }

    public static function responseContentProvider(): Generator
    {
        foreach (['Throwable', 'RequestException'] as $exceptionType) {
            yield 'not error response ' . ' ' . $exceptionType => [
                '{"data" : "test"}',
                false,
                $exceptionType,
            ];

            yield 'errors not array' . ' ' . $exceptionType => [
                '{"error": { "errors" : "test" }}',
                false,
                $exceptionType,
            ];

            yield 'errors empty array' . ' ' . $exceptionType => [
                '{"error": { "errors" : [] }}',
                false,
                $exceptionType,
            ];
            yield 'errors expected errors[0]' . ' ' . $exceptionType => [
                '{"error": { "errors" : [{"test":"test"}] }}',
                false,
                $exceptionType,
            ];

            yield 'errors no reason ' . $exceptionType => [
                '{"error": { "errors" : [{"message":"bigquery.jobs.create"}] }}',
                true,
                $exceptionType,
            ];

            yield 'errors no message ' . $exceptionType => [
                '{"error": { "errors" : [{"reason":"userRateLimitExceeded"}] }}',
                true,
                $exceptionType,
            ];

            yield 'unknown reason and message ' . $exceptionType => [
                '{"error": { "errors" : [{"reason":"unknown","message": "unknown"}] }}',
                false,
                $exceptionType,
            ];

            /**
             * @var array{error:array{
             *     code:int,
             *     message:string,
             *     status:string,
             *     errors:array<array{
             *          message:string,
             *          domain:string,
             *          reason:string
             *     }>
             *   }} $json
             */
            $json = json_decode(
                <<<EOD
{
    "error": {
        "code": 404,
        "message": "Not found: xxx",
        "errors": [
            {
                "message": "Not found: xxx",
                "domain": "global",
                "reason": "notFound"
            }
        ],
        "status": "NOT_FOUND"
    }
}
EOD,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            foreach ([
                         'rateLimitExceeded',
                         'userRateLimitExceeded',
                         'backendError',
                         'jobRateLimitExceeded',
                     ] as $reason) {
                $json['error']['errors'][0]['reason'] = $reason;
                yield 'retry on ' . $reason . ' ' . $exceptionType => [
                    json_encode($json, JSON_THROW_ON_ERROR),
                    true,
                    $exceptionType,
                ];
            }

            $json['error']['errors'][0]['reason'] = 'unknown';
            yield 'not retry on unknown reason ' . $exceptionType => [
                json_encode($json, JSON_THROW_ON_ERROR),
                false,
                $exceptionType,
            ];

            foreach ([
                         'bigquery.jobs.create',
                         //phpcs:ignore
                         'IAM setPolicy failed for Dataset xxxx:WORKSPACE_11111: Service account xxxx@xxxx.iam.gserviceaccount.com does not exist.',
                     ] as $msg) {
                $json['error']['errors'][0]['reason'] = 'unknown';
                $json['error']['errors'][0]['message'] = $msg;
                yield sprintf('retry on message "%s" "%s"', $msg, $exceptionType) => [
                    json_encode($json, JSON_THROW_ON_ERROR),
                    true,
                    $exceptionType,
                ];
            }
        }
    }

    #[DataProvider('responseContentProvider')]
    public function testResponseContent(string $json, bool $expectToRetry, string $exceptionType): void
    {
        $fn = Retry::getRetryDecider(new NullLogger());
        if ($exceptionType === 'RequestException') {
            $ex = $this->getRequestException(418, $json);
        } else {
            $ex = $this->getException(418, $json);
        }

        $this->assertSame($expectToRetry, $fn($ex));
    }

    public function testUnauthorizedIsNotRetriedWhenNotIncluded(): void
    {
        $fn = Retry::getRetryDecider(new NullLogger());

        $this->assertFalse($fn($this->getException(401), 0));
    }

    /**
     * @return int[][]
     */
    public static function unauthorizedWithinCapProvider(): array
    {
        return [[0], [1], [5]];
    }

    #[DataProvider('unauthorizedWithinCapProvider')]
    public function testUnauthorizedIsRetriedWithinTheCap(int $retryAttempt): void
    {
        $fn = Retry::getRetryDecider(new NullLogger(), true);

        $this->assertTrue($fn($this->getException(401), $retryAttempt));
    }

    /**
     * A 401 either clears in seconds or never; spending the caller's whole budget (20 for the BigQuery
     * client, backing off up to 60s a step) on a credential that is invalid for real is what turned a
     * permanent failure into an hours-long hang.
     *
     * @return int[][]
     */
    public static function unauthorizedBeyondCapProvider(): array
    {
        return [[6], [7], [19]];
    }

    #[DataProvider('unauthorizedBeyondCapProvider')]
    public function testUnauthorizedStopsBeingRetriedAfterTheCap(int $retryAttempt): void
    {
        $fn = Retry::getRetryDecider(new NullLogger(), true);

        $this->assertFalse($fn($this->getException(401), $retryAttempt));
    }

    /**
     * The cap is specific to 401 — throttling and backend errors do clear, so they keep the caller's
     * full budget however many attempts have already been spent.
     */
    #[DataProvider('retryCodesErrorProvider')]
    public function testAlwaysRetryCodesAreNotCappedByTheUnauthorizedLimit(int $statusCode): void
    {
        $fn = Retry::getRetryDecider(new NullLogger(), true);

        $this->assertTrue($fn($this->getException($statusCode), 99));
    }

    public function testRestRetryFunctionForwardsTheAttemptNumber(): void
    {
        // google/cloud-core calls the rest retry function as ($exception, $retryAttempt) from both
        // ExponentialBackoff and the async path; the cap is useless if that argument is dropped.
        $fn = Retry::getRestRetryFunction(new NullLogger(), true);

        $this->assertTrue($fn($this->getException(401), 0));
        $this->assertFalse($fn($this->getException(401), self::UNAUTHORIZED_RETRY_CAP));
    }
}
