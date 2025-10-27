<?php

namespace InfinityPaul\LaravelDynamoDbAuditing;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Illuminate\Support\Facades\Log;

class AuditQueryService
{
    protected DynamoDbClient $dynamoDb;
    protected Marshaler $marshaler;
    protected string $tableName;

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

        $this->dynamoDb = new DynamoDbClient($config);
        $this->marshaler = new Marshaler();
        $this->tableName = config('dynamodb-auditing.table_name');
    }


    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get all audit logs with pagination and optional filters
     */
    public function getAllAudits(int $limit = 25, ?array $lastEvaluatedKey = null, array $filters = []): array
    {
        $useGSI = config('dynamodb-auditing.enable_gsi', false) && $this->hasCreatedAtIndex();
        
        if ($useGSI) {
            $gsiOnly = config('dynamodb-auditing.gsi_only', false);
            
            if ($gsiOnly) {
                return $this->queryWithGSI($limit, $lastEvaluatedKey, $filters);
            }
            
            return $this->getCombinedAudits($limit, $lastEvaluatedKey, $filters);
        }
        
        return $this->scanAudits($limit, $lastEvaluatedKey, $filters);
    }

    /**
     * Get combined audit results from both GSI (new records) and scan (old records)
     */
    private function getCombinedAudits(int $limit = 25, ?array $lastEvaluatedKey = null, array $filters = []): array
    {
        try {
            $gsiResults = $this->queryWithGSI($limit, null, $filters);
            $gsiItems = collect($gsiResults['items']);
            
            if ($gsiItems->count() >= $limit) {
                return $gsiResults;
            }
            
            $remainingLimit = $limit - $gsiItems->count();
            $scanResults = $this->scanAudits($remainingLimit, $lastEvaluatedKey, $filters);
            $scanItems = collect($scanResults['items']);
            
            $gsiAuditIds = $gsiItems->pluck('audit_id')->toArray();
            $uniqueScanItems = $scanItems->reject(function ($item) use ($gsiAuditIds) {
                return in_array($item['audit_id'] ?? null, $gsiAuditIds);
            });
            
            $combinedItems = $gsiItems->concat($uniqueScanItems)->sortByDesc(function ($item) {
                $createdAt = $item['created_at'] ?? null;
                if (!$createdAt) {
                    return 0;
                }
                try {
                    return \Carbon\Carbon::parse($createdAt)->timestamp;
                } catch (\Exception $e) {
                    return 0;
                }
            })->take($limit);
            
            return [
                'items' => $combinedItems->values()->toArray(),
                'count' => $combinedItems->count(),
                'scanned_count' => $gsiResults['scanned_count'] + $scanResults['scanned_count'],
                'lastEvaluatedKey' => $scanResults['lastEvaluatedKey'],
            ];
            
        } catch (\Exception $e) {
            Log::error('Error in getCombinedAudits, falling back to scan: ' . $e->getMessage());
            return $this->scanAudits($limit, $lastEvaluatedKey, $filters);
        }
    }
    
    private function hasCreatedAtIndex(): bool
    {
        try {
            $result = $this->dynamoDb->describeTable(['TableName' => $this->tableName]);
            $indexes = $result['Table']['GlobalSecondaryIndexes'] ?? [];
            
            foreach ($indexes as $index) {
                if ($index['IndexName'] === 'CreatedAtIndex') {
                    return $index['IndexStatus'] === 'ACTIVE';
                }
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function queryWithGSI(int $limit, ?array $lastEvaluatedKey, array $filters): array
    {
        $params = [
            'TableName' => $this->tableName,
            'IndexName' => 'CreatedAtIndex',
            'KeyConditionExpression' => 'audit_type = :audit_type',
            'ExpressionAttributeValues' => [
                ':audit_type' => ['S' => 'AUDIT']
            ],
            'ScanIndexForward' => false, 
            'Limit' => $limit,
        ];

        if ($lastEvaluatedKey) {
            $params['ExclusiveStartKey'] = $this->marshaler->marshalItem($lastEvaluatedKey);
        }

        $filterExpressions = [];
        if (!empty($filters['user_id'])) {
            $filterExpressions[] = 'user_id = :user_id';
            $params['ExpressionAttributeValues'][':user_id'] = ['N' => (string) $filters['user_id']];
        }
        if (!empty($filters['event'])) {
            $filterExpressions[] = '#event = :event';
            $params['ExpressionAttributeNames']['#event'] = 'event';
            $params['ExpressionAttributeValues'][':event'] = ['S' => $filters['event']];
        }
        if (!empty($filters['entity_type'])) {
            $filterExpressions[] = 'auditable_type = :auditable_type';
            $params['ExpressionAttributeValues'][':auditable_type'] = ['S' => $filters['entity_type']];
        }
        if (!empty($filters['entity_id'])) {
            $filterExpressions[] = 'auditable_id = :auditable_id';
            $params['ExpressionAttributeValues'][':auditable_id'] = ['N' => (string) $filters['entity_id']];
        }

        if (!empty($filterExpressions)) {
            $params['FilterExpression'] = implode(' AND ', $filterExpressions);
        }

        try {
            $result = $this->dynamoDb->query($params);

            $items = collect($result['Items'])->map(function ($item) {
                return $this->marshaler->unmarshalItem($item);
            });

            return [
                'items' => $items->values()->toArray(),
                'count' => $result['Count'] ?? 0,
                'scanned_count' => $result['ScannedCount'] ?? 0,
                'lastEvaluatedKey' => isset($result['LastEvaluatedKey']) ? $this->marshaler->unmarshalItem($result['LastEvaluatedKey']) : null,
            ];
        } catch (\Exception $e) {
            Log::error('Error querying DynamoDB GSI for audits: ' . $e->getMessage(), ['exception' => $e]);
            return $this->scanAudits($limit, $lastEvaluatedKey, $filters);
        }
    }
    
    private function scanAudits(int $limit, ?array $lastEvaluatedKey, array $filters): array
    {
        $params = [
            'TableName' => $this->tableName,
            'Limit' => $limit,
        ];

        if ($lastEvaluatedKey) {
            $params['ExclusiveStartKey'] = $this->marshaler->marshalItem($lastEvaluatedKey);
        }


        $filterExpressions = [];
        $expressionAttributeValues = [];
        $expressionAttributeNames = [];

        if (!empty($filters['user_id'])) {
            $filterExpressions[] = 'user_id = :user_id';
            $expressionAttributeValues[':user_id'] = ['N' => (string) $filters['user_id']];
        }
        if (!empty($filters['event'])) {
            $filterExpressions[] = '#event = :event';
            $expressionAttributeNames['#event'] = 'event';
            $expressionAttributeValues[':event'] = ['S' => $filters['event']];
        }
        if (!empty($filters['entity_type'])) {
            $filterExpressions[] = 'auditable_type = :auditable_type';
            $expressionAttributeValues[':auditable_type'] = ['S' => $filters['entity_type']];
        }
        if (!empty($filters['entity_id'])) {
            $filterExpressions[] = 'auditable_id = :auditable_id';
            $expressionAttributeValues[':auditable_id'] = ['N' => (string) $filters['entity_id']];
        }

        if (!empty($filterExpressions)) {
            $params['FilterExpression'] = implode(' AND ', $filterExpressions);
            $params['ExpressionAttributeValues'] = $expressionAttributeValues;
            if (!empty($expressionAttributeNames)) {
                $params['ExpressionAttributeNames'] = $expressionAttributeNames;
            }
        }

        try {
            $result = $this->dynamoDb->scan($params);

            $items = collect($result['Items'])->map(function ($item) {
                return $this->marshaler->unmarshalItem($item);
            });

           
            $sortedItems = $items->sortByDesc(function ($item) {
                $createdAt = $item['created_at'] ?? null;
                if (!$createdAt) {
                    return 0; 
                }
                
                try {
                    return \Carbon\Carbon::parse($createdAt)->timestamp;
                } catch (\Exception $e) {
                    return 0;
                }
            });

            return [
                'items' => $sortedItems->values()->toArray(),
                'count' => $result['Count'] ?? 0,
                'scanned_count' => $result['ScannedCount'] ?? 0,
                'lastEvaluatedKey' => isset($result['LastEvaluatedKey']) ? $this->marshaler->unmarshalItem($result['LastEvaluatedKey']) : null,
            ];
        } catch (\Exception $e) {
            Log::error('Error scanning DynamoDB for audits: ' . $e->getMessage(), ['exception' => $e]);
            return [
                'items' => [],
                'count' => 0,
                'scanned_count' => 0,
                'lastEvaluatedKey' => null,
            ];
        }
    }


    public function getAuditById(string $auditId): ?array
    {
        $params = [
            'TableName' => $this->tableName,
            'FilterExpression' => 'audit_id = :audit_id',
            'ExpressionAttributeValues' => [
                ':audit_id' => ['S' => $auditId],
            ],
        ];

        try {
            $result = $this->dynamoDb->scan($params);

            if (!empty($result['Items'][0])) {
                return $this->marshaler->unmarshalItem($result['Items'][0]);
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Error fetching audit by ID from DynamoDB: ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }
    
    public function getAuditStats(): array
    {
        // TODO: Implement statistics gathering
        return [
            'total_audits' => 0,
            'events_breakdown' => [],
        ];
    }

    /**
     * Unmarshall DynamoDB items
     */
    protected function unmarshallItems(array $items): array
    {
        return array_map(function ($item) {
            return $this->marshaler->unmarshalItem($item);
        }, $items);
    }
}
