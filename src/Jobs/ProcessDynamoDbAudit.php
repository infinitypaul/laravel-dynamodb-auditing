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
            Log::info('ProcessDynamoDbAudit Job - Starting', [
                'audit_id' => $this->auditData['audit_id'] ?? 'unknown',
                'table_name' => $this->tableName,
                'model_type' => $this->auditData['auditable_type'] ?? 'unknown',
                'model_id' => $this->auditData['auditable_id'] ?? 'unknown',
                'event' => $this->auditData['event'] ?? 'unknown',
                'job_id' => $this->job->getJobId(),
            ]);

            $dynamoDb = new DynamoDbClient($this->dynamoDbConfig);
            $marshaler = new Marshaler();

            $dynamoDb->putItem([
                'TableName' => $this->tableName,
                'Item' => $marshaler->marshalItem($this->auditData),
            ]);

            Log::info('ProcessDynamoDbAudit Job - Successfully completed', [
                'audit_id' => $this->auditData['audit_id'] ?? 'unknown',
                'job_id' => $this->job->getJobId(),
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessDynamoDbAudit Job - Failed', [
                'audit_id' => $this->auditData['audit_id'] ?? 'unknown',
                'table_name' => $this->tableName,
                'error' => $e->getMessage(),
                'job_id' => $this->job->getJobId(),
                'attempt' => $this->attempts(),
            ]);
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessDynamoDbAudit Job - Failed permanently', [
            'audit_id' => $this->auditData['audit_id'] ?? 'unknown',
            'table_name' => $this->tableName,
            'model_type' => $this->auditData['auditable_type'] ?? 'unknown',
            'model_id' => $this->auditData['auditable_id'] ?? 'unknown',
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'attempts' => $this->attempts(),
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }
}
