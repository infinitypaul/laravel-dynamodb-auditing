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

    public int $tries = 5;
    public int $maxExceptions = 5;
    public int $timeout = 120;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [10, 30, 90, 180, 300];
    }

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
            // Re-throw to trigger retry mechanism
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Silently fail - audit failures should not break the application
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(12);
    }
}
