<?php

namespace InfinityPaul\LaravelDynamoDbAuditing\Console\Commands;

use Illuminate\Console\Command;
use InfinityPaul\LaravelDynamoDbAuditing\AuditQueryService;
use OwenIt\Auditing\Facades\Auditor;

class TestDynamoDbAudit extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'audit:test-dynamodb 
                            {--model=App\\Models\\User : Model class to test with}
                            {--id=1 : Model ID to test with}';

    /**
     * The console command description.
     */
    protected $description = 'Test DynamoDB audit functionality';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧪 Testing DynamoDB Audit Functionality...');
        $this->newLine();

        try {
            $this->testConfiguration();

            $this->testDynamoDbConnection();

            $this->testAuditDriver();

            $this->testModelAudit();

            $this->newLine();
            $this->info('🎉 All tests passed! DynamoDB auditing is working correctly.');
            
            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Test failed: ' . $e->getMessage());
            
            if ($this->output->isVerbose()) {
                $this->error('Stack trace: ' . $e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }

    /**
     * Test configuration
     */
    private function testConfiguration(): void
    {
        $this->info('1️⃣ Testing Configuration...');

        $auditDriver = config('audit.driver');
        $dynamoConfig = config('dynamodb-auditing');
        
        $this->line("   Audit driver: {$auditDriver}");
        $this->line("   DynamoDB table: " . ($dynamoConfig['table_name'] ?? 'Not set'));
        $this->line("   TTL days: " . ($dynamoConfig['ttl_days'] ?? 'Infinite'));
        
        if ($auditDriver !== 'dynamodb') {
            throw new \Exception("Audit driver is '{$auditDriver}', expected 'dynamodb'. Check your AUDIT_DRIVER environment variable.");
        }
        
        if (empty($dynamoConfig['table_name'])) {
            throw new \Exception('DynamoDB table name not configured. Check DYNAMODB_AUDIT_TABLE environment variable.');
        }
        
        $this->info('   ✅ Configuration looks good');
    }

    /**
     * Test DynamoDB connection
     */
    private function testDynamoDbConnection(): void
    {
        $this->info('2️⃣ Testing DynamoDB Connection...');

        $queryService = app(AuditQueryService::class);

        $tableName = $queryService->getTableName();
        $this->line("   Table name: {$tableName}");

        $result = $queryService->getAllAudits(5, null, []);
        $this->line("   Current records: " . $result['count']);
        $this->line("   Scanned records: " . $result['scanned_count']);
        
        $this->info('   ✅ DynamoDB connection working');
    }

    /**
     * Test audit driver registration
     */
    private function testAuditDriver(): void
    {
        $this->info('3️⃣ Testing Audit Driver...');

        $modelClass = $this->option('model');
        
        if (!class_exists($modelClass)) {
            $this->warn("   Model class {$modelClass} not found, skipping driver test");
            return;
        }

        $model = new $modelClass;
        
        if (!method_exists($model, 'getAuditDriver')) {
            $this->warn("   Model {$modelClass} doesn't implement Auditable interface, skipping");
            return;
        }

        $auditor = Auditor::getFacadeRoot();
        $driver = $auditor->auditDriver($model);
        
        $this->line("   Driver class: " . get_class($driver));
        
        if (!$driver instanceof \InfinityPaul\LaravelDynamoDbAuditing\DynamoDbAuditDriver) {
            throw new \Exception('Expected DynamoDbAuditDriver, got ' . get_class($driver));
        }
        
        $this->info('   ✅ Audit driver correctly registered');
    }

    /**
     * Test with actual model
     */
    private function testModelAudit(): void
    {
        $this->info('4️⃣ Testing Model Audit...');

        $modelClass = $this->option('model');
        $modelId = $this->option('id');
        
        if (!class_exists($modelClass)) {
            $this->warn("   Model class {$modelClass} not found, skipping model test");
            return;
        }

        try {
            $model = $modelClass::find($modelId);
            
            if (!$model) {
                $this->warn("   Model {$modelClass} with ID {$modelId} not found, skipping model test");
                return;
            }

            if (!method_exists($model, 'getAuditDriver')) {
                $this->warn("   Model {$modelClass} doesn't implement Auditable interface, skipping");
                return;
            }

            $this->line("   Testing with: {$modelClass} #{$modelId}");

            $queryService = app(AuditQueryService::class);
            $beforeCount = $queryService->getAllAudits(1, null, [])['count'];

            $testField = $this->getTestField($model);
            
            if ($testField) {
                $this->line("   Updating field: {$testField}");
                
                $originalValue = $model->{$testField};
                $testValue = $this->getTestValue($originalValue);

                $model->update([$testField => $testValue]);

                sleep(1);

                $afterCount = $queryService->getAllAudits(1, null, [])['count'];
                
                if ($afterCount > $beforeCount) {
                    $this->info("   ✅ Audit record created successfully!");
                    $this->line("   Records before: {$beforeCount}, after: {$afterCount}");
                } else {
                    $this->warn("   ⚠️  No new audit record detected");
                }

                $model->update([$testField => $originalValue]);
                $this->line("   Original value restored");
                
            } else {
                $this->warn("   No safe test field found for {$modelClass}, skipping update test");
            }

        } catch (\Exception $e) {
            $this->warn("   Model test failed: " . $e->getMessage());
        }
    }

    /**
     * Get a safe field to test with
     */
    private function getTestField($model): ?string
    {
        $auditInclude = method_exists($model, 'getAuditInclude') ? $model->getAuditInclude() : [];

        $safeFields = ['is_active', 'status', 'enabled', 'active'];
        
        foreach ($safeFields as $field) {
            if (in_array($field, $auditInclude) && $model->hasAttribute($field)) {
                return $field;
            }
        }

        if (!empty($auditInclude)) {
            return $auditInclude[0];
        }
        
        return null;
    }

    /**
     * Get a test value for the field
     */
    private function getTestValue($originalValue)
    {
        if (is_bool($originalValue)) {
            return !$originalValue;
        }
        
        if (is_numeric($originalValue)) {
            return $originalValue + 1;
        }
        
        if (is_string($originalValue)) {
            return $originalValue . '_test';
        }
        
        return 'test_value';
    }
}
