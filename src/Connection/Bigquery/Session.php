<?php

declare(strict_types=1);

namespace Keboola\TableBackendUtils\Connection\Bigquery;

use Google\Cloud\BigQuery\Job;

class Session
{
    private string $sessionId;

    public function __construct(string $sessionId)
    {
        $this->sessionId = $sessionId;
    }

    public static function createFromJob(Job $job): self
    {
        /** @var array{statistics:array{sessionInfo:array{sessionId:string}}} $info */
        $info = $job->info();
        return new self($info['statistics']['sessionInfo']['sessionId']);
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * @return array{
     *  configuration: array{
     *      query: array{
     *          connectionProperties: array{
     *              key: string,
     *              value: string
     *          }
     *      }
     *  }
     * }
     */
    public function getAsQueryOptions(): array
    {
        return [
            'configuration' => [
                'query' => [
                    'connectionProperties' => [
                        'key' => 'session_id',
                        'value' => $this->sessionId,
                    ],
                ],
            ],
        ];
    }
}
