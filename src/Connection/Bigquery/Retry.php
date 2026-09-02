<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Bigquery;

use Closure;
use GuzzleHttp\Exception\BadResponseException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

final class Retry
{
    private const RETRY_MISSING_CREATE_JOB = 'bigquery.jobs.create';
    private const RETRY_SERVICE_ACCOUNT_NOT_EXIST = 'IAM setPolicy failed for Dataset';
    private const RETRY_ON_REASON = [
        'rateLimitExceeded',
        'userRateLimitExceeded',
        'backendError',
        'jobRateLimitExceeded',
    ];
    private const ALWAYS_RETRY_STATUS_CODES = [429, 500, 503];

    /**
     * How many times a 401 may be retried when $includeUnauthorized is on.
     *
     * The caller's own retry budget (20 for the BigQuery client) is meant for throttling and backend
     * errors, which do clear. A 401 either clears within seconds — a spurious one, or a key that has
     * not finished propagating — or never, because the credential is genuinely invalid or revoked.
     * Spending the full budget on the second case turns a permanent failure into a very long hang:
     * cloud-core's delay is `min(2^attempt + jitter, 60s)`, so 20 attempts is roughly 14 minutes per
     * call, and a caller that loops (a smoke test, a poll) multiplies that into hours.
     *
     * Six retries is ~63s of tolerance (1+2+4+8+16+32), which covers the propagation window this
     * feature exists for while bounding the hopeless case to about a minute.
     */
    private const MAX_UNAUTHORIZED_RETRIES = 6;

    /**
     * helper method to overcome some irregular behavior of google bigquery client
     */
    public static function getRestRetryFunction(LoggerInterface $logger, bool $includeUnauthorized = false): Closure
    {
        return static function () use ($logger, $includeUnauthorized): Closure|bool {
            // BigQuery client sometimes calls directly restRetryFunction with exception as first argument
            // But in other cases it expects to return callable which accepts exception as first argument
            $argsNum = func_num_args();
            if ($argsNum === 2) {
                $ex = func_get_arg(0);
                $retryAttempt = func_get_arg(1);
                if ($ex instanceof Throwable) {
                    /** @var bool */
                    return Retry::getRetryDecider($logger, $includeUnauthorized)(
                        $ex,
                        is_int($retryAttempt) ? $retryAttempt : 0,
                    );
                }
            }
            return Retry::getRetryDecider($logger, $includeUnauthorized);
        };
    }

    /**
     * The returned closure takes `($exception, $retryAttempt)`, which is how google/cloud-core calls a
     * retry function from both ExponentialBackoff and its async path. `$retryAttempt` is zero-based and
     * defaults to 0, so a caller that passes only the exception keeps its previous behaviour.
     *
     * @param bool $includeUnauthorized default false. Google Cloud sometimes returns 401 even when the
     *     credentials are correct, so retrying helps — but for credentials that are invalid for real it
     *     used to mean a long waiting loop, which is why the 401 retry is now capped at
     *     {@see self::MAX_UNAUTHORIZED_RETRIES}. Everything else keeps the caller's full budget.
     */
    public static function getRetryDecider(LoggerInterface $logger, bool $includeUnauthorized = false): Closure
    {
        return static function (Throwable $ex, int $retryAttempt = 0) use ($logger, $includeUnauthorized): bool {
            $statusCode = $ex->getCode();

            if ($includeUnauthorized && $statusCode === 401) {
                if ($retryAttempt < self::MAX_UNAUTHORIZED_RETRIES) {
                    Retry::logRetry($statusCode, [], $logger);
                    return true;
                }

                Retry::logNotRetry(
                    $statusCode,
                    sprintf('giving up after %d unauthorized retries', self::MAX_UNAUTHORIZED_RETRIES),
                    $logger,
                );
                return false;
            }

            if (in_array($statusCode, self::ALWAYS_RETRY_STATUS_CODES)) {
                Retry::logRetry($statusCode, [], $logger);
                return true;
            }
            if ($statusCode >= 200 && $statusCode < 300) {
                return false;
            }

            $message = $ex->getMessage();
            // BadResponseException is the only Guzzle exception guaranteed to carry a response in both Guzzle 7 and 8
            if ($ex instanceof BadResponseException) {
                $message = (string) $ex->getResponse()->getBody();
            }
            if (str_contains($message, self::RETRY_SERVICE_ACCOUNT_NOT_EXIST)) {
                Retry::logRetry($statusCode, [$message], $logger);
                return true;
            }
            if (str_contains($message, self::RETRY_MISSING_CREATE_JOB)) {
                Retry::logRetry($statusCode, $message, $logger);
                return true;
            }

            try {
                $decoded = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
                assert(is_array($decoded));
                /** @var array<string, mixed> $message */
                $message = $decoded;
            } catch (JsonException) {
                Retry::logNotRetry($statusCode, $message, $logger);
                return false;
            }

            if (!array_key_exists('error', $message)) {
                Retry::logNotRetry($statusCode, $message, $logger);
                return false;
            }

            /** @var array<string, mixed> $error */
            $error = $message['error'];
            if (!array_key_exists('errors', $error)) {
                Retry::logNotRetry($statusCode, $message, $logger);
                return false;
            }

            if (!is_array($error['errors'])) {
                Retry::logNotRetry($statusCode, $message, $logger);
                return false;
            }

            /** @var array<array<string, mixed>> $errors */
            $errors = $error['errors'];
            foreach ($errors as $errorEntry) {
                if (array_key_exists('reason', $errorEntry)
                    && in_array($errorEntry['reason'], self::RETRY_ON_REASON, false)
                ) {
                    Retry::logRetry($statusCode, $message, $logger);
                    return true;
                }
            }

            Retry::logNotRetry($statusCode, $message, $logger);

            return false;
        };
    }

    /**
     * @param array<mixed> $message
     * @throws JsonException
     */
    private static function logRetry(int $statusCode, array|string $message, LoggerInterface $logger): void
    {
        if (is_array($message)) {
            $message = json_encode($message, JSON_THROW_ON_ERROR);
        }

        $logger->log(
            LogLevel::INFO,
            sprintf(
                'Retrying [%s] request with exception::%s',
                $statusCode,
                $message,
            ),
        );
    }

    /**
     * @param array<mixed> $message
     * @throws JsonException
     */
    private static function logNotRetry(int $statusCode, string|array $message, LoggerInterface $logger): void
    {
        if (is_array($message)) {
            $message = json_encode($message, JSON_THROW_ON_ERROR);
        }
        $logger->log(
            LogLevel::INFO,
            sprintf(
                'Not retrying [%s] request with exception::%s',
                $statusCode,
                $message,
            ),
        );
    }
}
