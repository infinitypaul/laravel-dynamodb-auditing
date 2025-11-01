<?php

namespace InfinityPaul\LaravelDynamoDbAuditing;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use OwenIt\Auditing\Contracts\Audit;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\AuditDriver;
use InfinityPaul\LaravelDynamoDbAuditing\Jobs\ProcessDynamoDbAudit;
use Carbon\Carbon;

class DynamoDbAuditDriver implements AuditDriver
{
    protected DynamoDbClient $dynamoDb;
    protected Marshaler $marshaler;
    protected string $tableName;
    protected array $dynamoDbConfig;

    public function __construct()
    {
        if (app()->environment('local') && env('DYNAMODB_ENDPOINT') !== null) {
            $config = config('dynamodb-auditing.local');
        } else {
            $config = [
                'region' => config('dynamodb-auditing.region'),
                'version' => config('dynamodb-auditing.version'),
            ];
            
            $credentials = config('dynamodb-auditing.credentials');
            if (!empty($credentials['key']) && !empty($credentials['secret'])) {
                $config['credentials'] = $credentials;
            }
        }

        if (empty($config['endpoint'])) {
            unset($config['endpoint']);
        }

        $this->dynamoDbConfig = $config;
        $this->dynamoDb = new DynamoDbClient($config);
        $this->marshaler = new Marshaler();
        $this->tableName = config('dynamodb-auditing.table_name');
    }

    public function audit(Auditable $model): ?Audit
    {
        try {
            $auditData = $model->toAudit();


            $auditId = uniqid('audit_', true);

            $item = [
                'PK' => $this->getPartitionKey($auditData),
                'SK' => $this->getSortKey($auditData, $auditId),
                'audit_id' => $auditId,
                'audit_type' => 'AUDIT',
                'user_type' => $auditData['user_type'] ?? null,
                'user_id' => $auditData['user_id'] ?? null,
                'event' => $auditData['event'] ?? null,
                'auditable_type' => $auditData['auditable_type'] ?? null,
                'auditable_id' => $auditData['auditable_id'] ?? null,
                'old_values' => !empty($auditData['old_values']) ? json_encode($auditData['old_values']) : null,
                'new_values' => !empty($auditData['new_values']) ? json_encode($auditData['new_values']) : null,
                'url' => $auditData['url'] ?? null,
                'ip_address' => $auditData['ip_address'] ?? null,
                'user_agent' => $auditData['user_agent'] ?? null,
                'tags' => $auditData['tags'] ?? null,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ];

            $ttl = $this->calculateTTL();
            if ($ttl !== null) {
                $item['TTL'] = $ttl;
            }

            $item = array_filter($item, fn($value) => $value !== null);

            if (config('dynamodb-auditing.queue.enabled', false)) {

                $job = ProcessDynamoDbAudit::dispatch($item, $this->tableName, $this->dynamoDbConfig);
                
                if ($connection = config('dynamodb-auditing.queue.connection')) {
                    $job->onConnection($connection);
                }

                if ($queue = config('dynamodb-auditing.queue.queue')) {
                    $job->onQueue($queue);
                }

                return null;
            }


            $this->dynamoDb->putItem([
                'TableName' => $this->tableName,
                'Item' => $this->marshaler->marshalItem($item),
            ]);


            return null;

        } catch (\Exception $e) {
            
            return null;
        }
    }

    public function prune(Auditable $model): bool
    {
        return true;
    }

    protected function getPartitionKey(array $auditData): string
    {
        if (!empty($auditData['user_id'])) {
            return "USER#{$auditData['user_id']}";
        }
        return "{$auditData['auditable_type']}#{$auditData['auditable_id']}";
    }

    protected function getSortKey(array $auditData, string $auditId): string
    {
        $timestamp = now()->toISOString();
        $event = $auditData['event'] ?? 'unknown';
        return "{$timestamp}#{$event}#{$auditId}";
    }

    protected function calculateTTL(): ?int
    {
        $ttlDays = config('dynamodb-auditing.ttl_days', 730);

        if ($ttlDays === null) {
            return null;
        }
        
        return now()->addDays($ttlDays)->timestamp;
    }
}
