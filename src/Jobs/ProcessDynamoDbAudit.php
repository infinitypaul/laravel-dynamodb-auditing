<?php

namespace InfinityPaul\LaravelDynamoDbAuditing\Jobs;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDynamoDbAudit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $timeout = 60;

    public function __construct(
        private array $auditData,
        private string $tableName,
        private array $dynamoDbConfig
    ) {}

    public function handle(): void
    {
        try {
            $dynamoDb = new DynamoDbClient($this->dynamoDbConfig);
            $marshaler = new Marshaler();

            $dynamoDb->putItem([
                'TableName' => $this->tableName,
                'Item' => $marshaler->marshalItem($this->auditData),
            ]);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Job failed permanently after all retries
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }
}
