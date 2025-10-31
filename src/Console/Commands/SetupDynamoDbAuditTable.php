<?php

namespace InfinityPaul\LaravelDynamoDbAuditing\Console\Commands;

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SetupDynamoDbAuditTable extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'audit:setup-dynamodb 
                            {--force : Force table recreation if it exists}
                            {--local : Setup for local development (uses local DynamoDB)}';

    /**
     * The console command description.
     */
    protected $description = 'Create DynamoDB table for audit logs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Setting up DynamoDB Audit Table...');
        $this->newLine();

        try {
            $config = $this->getDynamoDbConfig();
            $tableName = config('dynamodb-auditing.table_name');
            $isLocal = $this->option('local') || app()->environment('local');

            $this->info("📋 Configuration:");
            $this->line("   Environment: " . ($isLocal ? 'Local' : 'Production'));
            $this->line("   Table Name: {$tableName}");
            $this->line("   Region: " . ($config['region'] ?? 'N/A'));
            $this->line("   Endpoint: " . ($config['endpoint'] ?? 'Default AWS'));
            $this->newLine();

            $dynamoDb = new DynamoDbClient($config);

            $tableExists = $this->tableExists($dynamoDb, $tableName);

            if ($tableExists && !$this->option('force')) {
                $this->warn("⚠️  Table '{$tableName}' already exists!");
                
                if (!$this->confirm('Do you want to recreate it? (This will delete all existing audit data)')) {
                    $this->info('✅ Setup cancelled. Existing table preserved.');
                    return self::SUCCESS;
                }
            }

            if ($tableExists && ($this->option('force') || $this->confirm('Confirm table recreation?'))) {
                $this->deleteTable($dynamoDb, $tableName);
            }

            $this->createTable($dynamoDb, $tableName, $isLocal);

            $this->newLine();
            $this->info('🎉 DynamoDB Audit Table setup completed successfully!');
            $this->newLine();

            $this->showNextSteps($isLocal);

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Setup failed: ' . $e->getMessage());
            
            if ($this->output->isVerbose()) {
                $this->error('Stack trace: ' . $e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }

    /**
     * Get DynamoDB configuration based on environment
     */
    private function getDynamoDbConfig(): array
    {
        $config = config('dynamodb-auditing');

        if ($this->option('local') || app()->environment('local')) {
            return config('dynamodb-auditing.local', [
                'region' => 'us-east-1',
                'version' => 'latest',
                'endpoint' => 'http://localhost:8000',
                'credentials' => [
                    'key' => 'dummy',
                    'secret' => 'dummy',
                ],
            ]);
        }

        $prodConfig = [
            'region' => $config['region'] ?? config('aws.region', 'us-east-1'),
            'version' => 'latest',
        ];

        if (!empty($config['key']) && !empty($config['secret'])) {
            $prodConfig['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
        }

        return $prodConfig;
    }

    /**
     * Check if table exists
     */
    private function tableExists(DynamoDbClient $dynamoDb, string $tableName): bool
    {
        try {
            $result = $dynamoDb->listTables();
            return in_array($tableName, $result['TableNames']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete existing table
     */
    private function deleteTable(DynamoDbClient $dynamoDb, string $tableName): void
    {
        $this->warn("🗑️  Deleting existing table '{$tableName}'...");
        
        $dynamoDb->deleteTable(['TableName' => $tableName]);
        
        $this->info('   Waiting for table deletion...');
        $dynamoDb->waitUntil('TableNotExists', [
            'TableName' => $tableName,
            '@waiter' => [
                'delay' => 2,
                'maxAttempts' => 30
            ]
        ]);
        
        $this->info('   ✅ Table deleted successfully');
    }

    /**
     * Create the audit table
     */
    private function createTable(DynamoDbClient $dynamoDb, string $tableName, bool $isLocal): void
    {
        $this->info("🔨 Creating table '{$tableName}' with GSI for recent browsing...");

        $tableConfig = [
            'TableName' => $tableName,
            'AttributeDefinitions' => [
                [
                    'AttributeName' => 'PK',
                    'AttributeType' => 'S'
                ],
                [
                    'AttributeName' => 'SK',
                    'AttributeType' => 'S'
                ],
                [
                    'AttributeName' => 'audit_type',
                    'AttributeType' => 'S'
                ],
                [
                    'AttributeName' => 'created_at',
                    'AttributeType' => 'S'
                ]
            ],
            'KeySchema' => [
                [
                    'AttributeName' => 'PK',
                    'KeyType' => 'HASH'
                ],
                [
                    'AttributeName' => 'SK',
                    'KeyType' => 'RANGE'
                ]
            ],
            'GlobalSecondaryIndexes' => [
                [
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
                    ]
                ]
            ],
            'BillingMode' => 'PAY_PER_REQUEST'
        ];

        $ttlDays = config('dynamodb-auditing.ttl_days');
        if ($ttlDays !== null) {
            $this->info("   📅 TTL configured: {$ttlDays} days");
        } else {
            $this->info("   ♾️  TTL: Infinite retention");
        }

        $result = $dynamoDb->createTable($tableConfig);
        
        $this->info('   Table creation initiated...');
        $this->info('   Status: ' . $result['TableDescription']['TableStatus']);

        $this->info('   Waiting for table to become active...');
        $dynamoDb->waitUntil('TableExists', [
            'TableName' => $tableName,
            '@waiter' => [
                'delay' => 2,
                'maxAttempts' => 30
            ]
        ]);

        if ($ttlDays !== null) {
            $this->configureTTL($dynamoDb, $tableName);
        }

        $this->info('   ✅ Table created and active');
    }

    /**
     * Configure TTL on the table
     */
    private function configureTTL(DynamoDbClient $dynamoDb, string $tableName): void
    {
        try {
            $this->info('   ⏰ Configuring TTL...');
            
            $dynamoDb->updateTimeToLive([
                'TableName' => $tableName,
                'TimeToLiveSpecification' => [
                    'AttributeName' => 'TTL',
                    'Enabled' => true
                ]
            ]);
            
            $this->info('   ✅ TTL configured successfully');
        } catch (\Exception $e) {
            $this->warn('   ⚠️  TTL configuration failed: ' . $e->getMessage());
        }
    }

    /**
     * Show next steps after setup
     */
    private function showNextSteps(bool $isLocal): void
    {
        $this->info('📋 Next Steps:');
        $this->newLine();

        if ($isLocal) {
            $this->line('1. ✅ Your local DynamoDB table is ready');
            $this->line('2. 🔧 Make sure your .env has:');
            $this->line('   AUDIT_DRIVER=dynamodb');
            $this->line('   DYNAMODB_AUDIT_TABLE=' . config('dynamodb-auditing.table_name'));
            $this->line('   DYNAMODB_AUDIT_TTL_DAYS=' . (config('dynamodb-auditing.ttl_days') ?? 'null'));
        } else {
            $this->line('1. ✅ Your production DynamoDB table is ready');
            $this->line('2. 🔧 Make sure your production environment has:');
            $this->line('   AUDIT_DRIVER=dynamodb');
            $this->line('   DYNAMODB_AUDIT_TABLE=' . config('dynamodb-auditing.table_name'));
            $this->line('   AWS_ACCESS_KEY_ID=<your-key>');
            $this->line('   AWS_SECRET_ACCESS_KEY=<your-secret>');
            $this->line('   AWS_DEFAULT_REGION=' . config('dynamodb-auditing.region', 'us-east-1'));
        }

        $this->newLine();
        $this->line('3. 🧪 Test the setup:');
        $this->line('   php artisan audit:test-dynamodb');
        $this->newLine();
    }
}
