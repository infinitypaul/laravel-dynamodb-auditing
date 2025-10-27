<?php

namespace InfinityPaul\LaravelDynamoDbAuditing\Jobs;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
            Log::error('Failed to process DynamoDB audit in queue', [
                'error' => $e->getMessage(),
                'audit_data' => $this->auditData,
                'table' => $this->tableName,
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DynamoDB audit job failed permanently', [
            'error' => $exception->getMessage(),
            'audit_data' => $this->auditData,
            'table' => $this->tableName,
            'attempts' => $this->attempts(),
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }
}
