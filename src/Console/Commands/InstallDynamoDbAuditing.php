<?php

namespace InfinityPaul\LaravelDynamoDbAuditing\Console\Commands;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InfinityPaul\LaravelDynamoDbAuditing\AuditQueryService;
use OwenIt\Auditing\Facades\Auditor;

class InstallDynamoDbAuditing extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'audit:install-dynamodb 
                            {--force : Skip confirmations and force all actions}
                            {--local : Setup for local development only}
                            {--production : Setup for production only}';

    /**
     * The console command description.
     */
    protected $description = 'Interactive installer for DynamoDB auditing - handles complete setup';

    private bool $isLocal = false;
    private bool $forceMode = false;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->forceMode = $this->option('force');
        
        $this->displayWelcome();
        
        try {
            $this->determineEnvironment();

            $this->handleMigrationConflicts();

            $this->publishConfiguration();

            $this->configureEnvironment();

            $this->setupDynamoDbTable();

            $this->testInstallation();

            $this->showCompletionSummary();
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('❌ Installation failed: ' . $e->getMessage());
            
            if ($this->output->isVerbose()) {
                $this->error('Stack trace: ' . $e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }

    private function displayWelcome(): void
    {
        $this->info('🚀 DynamoDB Auditing Interactive Installer');
        $this->info('=========================================');
        $this->newLine();
        $this->line('This installer will:');
        $this->line('  ✅ Check for migration conflicts');
        $this->line('  ✅ Publish configuration files');
        $this->line('  ✅ Guide environment setup');
        $this->line('  ✅ Create DynamoDB table');
        $this->line('  ✅ Test the installation');
        $this->newLine();
    }

    private function determineEnvironment(): void
    {
        $this->info('📋 Step 1: Environment Detection');
        $this->line('──────────────────────────────────');
        
        if ($this->option('local')) {
            $this->isLocal = true;
            $this->info('   🏠 Local development mode (forced by --local flag)');
        } elseif ($this->option('production')) {
            $this->isLocal = false;
            $this->info('   🏭 Production mode (forced by --production flag)');
        } else {
            $currentEnv = app()->environment();
            $this->line("   Current Laravel environment: {$currentEnv}");
            
            if (in_array($currentEnv, ['local', 'development', 'dev'])) {
                $this->isLocal = $this->forceMode || $this->confirm('Setup for local development?', true);
            } else {
                $this->isLocal = !$this->forceMode && $this->confirm('Setup for local development?', false);
            }
        }
        
        $envType = $this->isLocal ? 'Local Development' : 'Production';
        $this->info("   ✅ Environment: {$envType}");
        $this->newLine();
    }

    private function handleMigrationConflicts(): void
    {
        $this->info('🔍 Step 2: Migration Conflict Check');
        $this->line('──────────────────────────────────────');
        
        $migrationPath = database_path('migrations');
        $auditMigrations = $this->findAuditMigrations($migrationPath);
        
        if (empty($auditMigrations)) {
            $this->info('   ✅ No conflicting MySQL audit migrations found');
            $this->newLine();
            return;
        }
        
        $this->warn('   ⚠️  Found conflicting MySQL audit migrations:');
        foreach ($auditMigrations as $migration) {
            $this->line("      📄 {$migration}");
        }
        $this->newLine();
        
        $this->warn('   🚨 These will create MySQL tables when you run "php artisan migrate"');
        $this->warn('      Since you\'re using DynamoDB, these should be removed to prevent conflicts.');
        $this->newLine();
        
        $shouldRemove = $this->forceMode || $this->confirm('Remove conflicting MySQL audit migrations?', true);
        
        if ($shouldRemove) {
            $removed = 0;
            foreach ($auditMigrations as $migration) {
                $fullPath = $migrationPath . '/' . $migration;
                if (File::delete($fullPath)) {
                    $this->info("      🗑️  Removed: {$migration}");
                    $removed++;
                } else {
                    $this->error("      ❌ Failed to remove: {$migration}");
                }
            }
            
            if ($removed > 0) {
                $this->info("   ✅ Successfully removed {$removed} conflicting migration(s)");
            }
        } else {
            $this->warn('   ⚠️  Migrations preserved - you may need to remove them manually later');
        }
        
        $this->newLine();
    }

    private function publishConfiguration(): void
    {
        $this->info('📦 Step 3: Configuration Publishing');
        $this->line('───────────────────────────────────────');
        
        $configPath = config_path('dynamodb-auditing.php');
        $configExists = File::exists($configPath);
        
        if ($configExists) {
            $this->line('   📄 Configuration file already exists');
            
            $shouldOverwrite = $this->forceMode || $this->confirm('Overwrite existing configuration?', false);
            
            if ($shouldOverwrite) {
                $this->call('vendor:publish', [
                    '--tag' => 'dynamodb-auditing-config',
                    '--force' => true
                ]);
                $this->info('   ✅ Configuration updated');
            } else {
                $this->info('   ✅ Using existing configuration');
            }
        } else {
            $this->call('vendor:publish', [
                '--tag' => 'dynamodb-auditing-config'
            ]);
            $this->info('   ✅ Configuration published');
        }
        
        $this->newLine();
    }

    private function configureEnvironment(): void
    {
        $this->info('⚙️  Step 4: Environment Configuration');
        $this->line('────────────────────────────────────────');
        
        $envPath = base_path('.env');
        $envContent = File::exists($envPath) ? File::get($envPath) : '';
        
        $this->line('   📝 Required environment variables:');
        $this->newLine();

        if (!str_contains($envContent, 'AUDIT_DRIVER=')) {
            $this->warn('   ❌ AUDIT_DRIVER not set');
            $this->line('      Add to .env: AUDIT_DRIVER=dynamodb');
        } elseif (!str_contains($envContent, 'AUDIT_DRIVER=dynamodb')) {
            $this->warn('   ⚠️  AUDIT_DRIVER is not set to dynamodb');
            $this->line('      Update .env: AUDIT_DRIVER=dynamodb');
        } else {
            $this->info('   ✅ AUDIT_DRIVER=dynamodb');
        }

        $tableName = $this->isLocal ? 'optimus-audit-logs-local' : 'your-app-audit-logs';
        if (!str_contains($envContent, 'DYNAMODB_AUDIT_TABLE=')) {
            $this->warn('   ❌ DYNAMODB_AUDIT_TABLE not set');
            $this->line("      Add to .env: DYNAMODB_AUDIT_TABLE={$tableName}");
        } else {
            $this->info('   ✅ DYNAMODB_AUDIT_TABLE configured');
        }

        if ($this->isLocal) {
            $this->line('   🏠 Local development variables:');
            $currentEndpoint = config('dynamodb-auditing.local.endpoint', env('DYNAMODB_ENDPOINT'));
            
            if (!str_contains($envContent, 'DYNAMODB_ENDPOINT=')) {
                $this->warn('      ❌ DYNAMODB_ENDPOINT not set');
                $this->line('         Add to .env: DYNAMODB_ENDPOINT=http://localhost:8000');
            } else {
                $this->info("      ✅ DYNAMODB_ENDPOINT configured: {$currentEndpoint}");
            }
        } else {
            $this->line('   🏭 Production variables:');
            $awsVars = ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION'];
            foreach ($awsVars as $var) {
                if (!str_contains($envContent, "{$var}=")) {
                    $this->warn("      ❌ {$var} not set");
                } else {
                    $this->info("      ✅ {$var} configured");
                }
            }
        }
        
        $this->newLine();
        $this->line('   💡 Tip: Update your .env file with the missing variables above');
        $this->newLine();
    }

    /**
     * @throws \Exception
     */
    private function setupDynamoDbTable(): void
    {
        $this->info('🔨 Step 5: DynamoDB Table Setup');
        $this->line('──────────────────────────────────');
        
        if ($this->isLocal) {
            $this->line('   🔍 Checking DynamoDB Local connection...');
            
            // Get endpoint from configuration
            $endpoint = config('dynamodb-auditing.local.endpoint', env('DYNAMODB_ENDPOINT', 'http://localhost:8000'));
            $this->line("   Endpoint: {$endpoint}");
            
            try {
                $response = @file_get_contents($endpoint, false, stream_context_create([
                    'http' => ['timeout' => 3]
                ]));
                $this->info('   ✅ DynamoDB Local is running');
            } catch (\Exception $e) {
                $this->error('   ❌ DynamoDB Local is not running');
                $this->line("      Endpoint checked: {$endpoint}");
                $this->line('      Start DynamoDB Local with: docker run -p 8000:8000 amazon/dynamodb-local:latest');
                $this->line('      Or update DYNAMODB_ENDPOINT in your .env if using different port/host');
                
                if (!$this->forceMode && !$this->confirm('Continue anyway? (table creation will fail)', false)) {
                    throw new \Exception('DynamoDB Local is required for local setup');
                }
            }
        }

        $setupArgs = [];
        if ($this->isLocal) {
            $setupArgs['--local'] = true;
        }
        if ($this->forceMode) {
            $setupArgs['--force'] = true;
        }
        
        $this->line('   🔨 Creating DynamoDB table...');
        $exitCode = $this->call('audit:setup-dynamodb', $setupArgs);
        
        if ($exitCode === 0) {
            $this->info('   ✅ DynamoDB table setup completed');
        } else {
            throw new \Exception('DynamoDB table setup failed');
        }
        
        $this->newLine();
    }

    private function testInstallation(): void
    {
        $this->info('🧪 Step 6: Installation Testing');
        $this->line('─────────────────────────────────');
        
        $shouldTest = $this->forceMode || $this->confirm('Run installation tests?', true);
        
        if (!$shouldTest) {
            $this->line('   ⏭️  Skipping tests');
            $this->newLine();
            return;
        }
        
        $this->line('   🔍 Running comprehensive tests...');
        
        $exitCode = $this->call('audit:test-dynamodb');
        
        if ($exitCode === 0) {
            $this->info('   🎉 All tests passed! Installation is working correctly.');
        } else {
            $this->warn('   ⚠️  Some tests failed - check the output above');
        }
        
        $this->newLine();
    }

    private function showCompletionSummary(): void
    {
        $this->info('🎉 Installation Complete!');
        $this->info('========================');
        $this->newLine();
        
        $envType = $this->isLocal ? 'local development' : 'production';
        $this->line("✅ DynamoDB auditing is now configured for {$envType}");
        $this->newLine();
        
        $this->info('📋 What was done:');
        $this->line('   ✅ Removed conflicting MySQL migrations');
        $this->line('   ✅ Published configuration files');
        $this->line('   ✅ Created DynamoDB audit table');
        $this->line('   ✅ Tested the installation');
        $this->newLine();
        
        $this->info('🚀 Next Steps:');
        $this->line('   1. Update your .env file with any missing variables shown above');
        $this->line('   2. Configure your models to use auditing (see documentation)');
        $this->line('   3. Run: php artisan migrate (safe - no conflicts!)');
        $this->newLine();
        
        $this->info('📚 Documentation:');
        $this->line('   • Package README: packages/laravel-dynamodb-auditing/README.md');
        $this->line('   • Installation Guide: packages/laravel-dynamodb-auditing/INSTALLATION.md');
        $this->newLine();
        
        $this->info('🔧 Useful Commands:');
        $this->line('   • Test auditing: php artisan audit:test-dynamodb');
        $this->line('   • Recreate table: php artisan audit:setup-dynamodb --force');
        $this->line('   • Check migrations: php artisan audit:prevent-migration --check');
        $this->newLine();
        
        $this->line('🎯 Happy auditing with DynamoDB! 🚀');
    }

    /**
     * Find audit-related migrations
     */
    private function findAuditMigrations(string $migrationPath): array
    {
        if (!File::isDirectory($migrationPath)) {
            return [];
        }

        $files = File::files($migrationPath);
        $auditMigrations = [];

        foreach ($files as $file) {
            $filename = $file->getFilename();

            if (preg_match('/.*create_audits?_table\.php$/', $filename)) {
                $auditMigrations[] = $filename;
            }
        }

        return $auditMigrations;
    }
}
