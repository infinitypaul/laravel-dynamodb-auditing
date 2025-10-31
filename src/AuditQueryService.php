<?php

namespace InfinityPaul\LaravelDynamoDbAuditing;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

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
     * Get audit logs with support for entity-specific and recent browsing
     * 
     * @param int $limit Maximum number of records to return
     * @param array|null $lastEvaluatedKey For pagination
     * @param array $filters Optional filters for searching
     * @return array
     */
    public function getAllAudits(int $limit = 25, ?array $lastEvaluatedKey = null, array $filters = []): array
    {
        if (!empty($filters['entity_id']) && !empty($filters['entity_type'])) {
            return $this->queryByPrimaryKey($filters['entity_id'], $limit, $filters['entity_type'], $filters);
        }
        
        if (empty($filters) || empty($filters['entity_id'])) {
            return $this->getRecentAudits($limit, $lastEvaluatedKey, $filters);
        }
        
        return [
            'items' => [],
            'count' => 0,
            'scanned_count' => 0,
            'lastEvaluatedKey' => null,
            'message' => 'For fast search, please provide both entity_id and entity_type'
        ];
    }

    /**
     * Get recent audit logs using GSI for default page load
     * 
     * @param int $limit Maximum number of records to return
     * @param array|null $lastEvaluatedKey For pagination
     * @param array $filters Optional filters
     * @return array
     */
    private function getRecentAudits(int $limit = 25, ?array $lastEvaluatedKey = null, array $filters = []): array
    {
        $endDate = now()->addHour()->toISOString();
        $startDate = now()->startOfDay()->toISOString();
        
        
        $params = [
            'TableName' => $this->tableName,
            'IndexName' => 'CreatedAtIndex',
            'KeyConditionExpression' => 'audit_type = :audit_type AND created_at BETWEEN :start_date AND :end_date',
            'ExpressionAttributeValues' => [
                ':audit_type' => ['S' => 'AUDIT'],
                ':start_date' => ['S' => $startDate],
                ':end_date' => ['S' => $endDate]
            ],
            'ScanIndexForward' => false,
            'Limit' => $limit,
        ];

        if ($lastEvaluatedKey) {
            $params['ExclusiveStartKey'] = $this->marshaler->marshalItem($lastEvaluatedKey);
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
            return [
                'items' => [],
                'count' => 0,
                'scanned_count' => 0,
                'lastEvaluatedKey' => null,
                'error' => 'Failed to load recent audits'
            ];
        }
    }

    /**
     * Execute Primary Key query for fast entity-specific lookups
     * 
     * @param string $entityId The entity ID to search for
     * @param int $limit Maximum number of records to return
     * @param string $entityType The entity type (e.g., App\Models\Wallet)
     * @param array $filters Additional filters including date range
     * @return array
     */
    private function queryByPrimaryKey(string $entityId, int $limit, string $entityType, array $filters = []): array
    {
        $pk = "{$entityType}#{$entityId}";
        $hasDateFilter = !empty($filters['start_date']) || !empty($filters['end_date']);
        $queryLimit = $hasDateFilter ? min($limit * 3, 1000) : $limit;
        
        $result = $this->executePKQuery($pk, $queryLimit);
        
        if ($hasDateFilter && !empty($result['items'])) {
            $result['items'] = $this->filterByDateRange($result['items'], $filters);
            $result['items'] = array_slice($result['items'], 0, $limit);
            $result['count'] = count($result['items']);
        }
        
        return $result;
    }

    /**
     * Filter audit records by date range
     *
     * @param array $items The audit records to filter
     * @param array $filters The filters containing start_date and/or end_date
     * @return array Filtered audit records
     * @throws \Exception
     */
    private function filterByDateRange(array $items, array $filters): array
    {
        $startDate = !empty($filters['start_date']) ? new \DateTime($filters['start_date']) : null;
        $endDate = !empty($filters['end_date']) ? new \DateTime($filters['end_date']) : null;
        
        return array_filter($items, function ($item) use ($startDate, $endDate) {
            if (empty($item['created_at'])) {
                return true;
            }
            
            try {
                $itemDate = new \DateTime($item['created_at']);
                
                if ($startDate && $itemDate < $startDate) {
                    return false;
                }
                
                if ($endDate && $itemDate > $endDate) {
                    return false;
                }
                
                return true;
            } catch (\Exception $e) {
                return true;
            }
        });
    }

    /**
     * Execute the actual DynamoDB Primary Key query
     * 
     * @param string $pk The primary key to query
     * @param int $limit Maximum number of records to return
     * @return array
     */
    private function executePKQuery(string $pk, int $limit): array
    {
        $params = [
            'TableName' => $this->tableName,
            'KeyConditionExpression' => 'PK = :pk',
            'ExpressionAttributeValues' => [
                ':pk' => ['S' => $pk]
            ],
            'ScanIndexForward' => false,
            'Limit' => $limit,
        ];
        
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
            return [
                'items' => [],
                'count' => 0,
                'scanned_count' => 0,
                'lastEvaluatedKey' => null,
                'error' => 'Query failed: ' . $e->getMessage()
            ];
        }
    }

}