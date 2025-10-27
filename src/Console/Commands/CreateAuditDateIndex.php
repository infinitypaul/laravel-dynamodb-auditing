<?php

namespace InfinityPaul\LaravelDynamoDbAuditing\Console\Commands;

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Console\Command;

class CreateAuditDateIndex extends Command
{
    protected $signature = 'dynamodb-audit:create-date-index';
    protected $description = 'Create a Global Secondary Index on created_at for immediate audit visibility';

    public function handle(): int
    {
        $this->info('Creating GSI for immediate audit visibility...');

        try {
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

            $dynamoDb = new DynamoDbClient($config);
            $tableName = config('dynamodb-auditing.table_name');

            $this->info("Adding GSI to table: {$tableName}");

            $result = $dynamoDb->updateTable([
                'TableName' => $tableName,
                'AttributeDefinitions' => [
                    [
                        'AttributeName' => 'created_at',
                        'AttributeType' => 'S'
                    ],
                    [
                        'AttributeName' => 'audit_type',
                        'AttributeType' => 'S'
                    ]
                ],
                'GlobalSecondaryIndexUpdates' => [
                    [
                        'Create' => [
                            'IndexName' => 'CreatedAtIndex',
                            'KeySchema' => [
                                [
                                    'AttributeName' => 'audit_type',
                                    'KeyType' => 'HASH'
                                ],
                                [
                                    'AttributeName' => 'created_at',
                                    'KeyType' => 'RANGE'
                                ]
                            ],
                            'Projection' => [
                                'ProjectionType' => 'ALL'
                            ],
                            'ProvisionedThroughput' => [
                                'ReadCapacityUnits' => 5,
                                'WriteCapacityUnits' => 5
                            ]
                        ]
                    ]
                ]
            ]);

            $this->info('✅ GSI creation initiated successfully!');
            $this->info('⏳ Index is being created... This may take several minutes.');
            $this->info('💡 Once complete, audit queries will have immediate consistency!');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Failed to create GSI: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
