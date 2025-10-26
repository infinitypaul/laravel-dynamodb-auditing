<?php

namespace InfinityPaul\LaravelDynamoDbAuditing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PreventAuditMigration extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'audit:prevent-migration 
                            {--check : Only check if migration exists without removing}';

    /**
     * The console command description.
     */
    protected $description = 'Prevent Laravel Auditing MySQL migration from running when using DynamoDB';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Checking for Laravel Auditing MySQL migrations...');
        $this->newLine();

        $migrationPath = database_path('migrations');
        $auditMigrations = $this->findAuditMigrations($migrationPath);

        if (empty($auditMigrations)) {
            $this->info('✅ No Laravel Auditing MySQL migrations found.');
            $this->line('   Your setup is clean for DynamoDB-only auditing.');
            return self::SUCCESS;
        }

        $this->warn('⚠️  Found Laravel Auditing MySQL migration(s):');
        foreach ($auditMigrations as $migration) {
            $this->line("   📄 {$migration}");
        }
        $this->newLine();

        if ($this->option('check')) {
            $this->info('📋 Recommendation:');
            $this->line('   Run: php artisan audit:prevent-migration');
            $this->line('   This will remove the MySQL migrations since you\'re using DynamoDB.');
            return self::SUCCESS;
        }

        $this->warn('🚨 These migrations will create MySQL tables when you run "php artisan migrate"');
        $this->warn('   Since you\'re using DynamoDB auditing, these are not needed and may cause conflicts.');
        $this->newLine();

        if (!$this->confirm('Remove these MySQL audit migrations?', true)) {
            $this->info('✅ Migrations preserved. You can remove them manually later if needed.');
            return self::SUCCESS;
        }

        $removed = 0;
        foreach ($auditMigrations as $migration) {
            $fullPath = $migrationPath . '/' . $migration;
            if (File::delete($fullPath)) {
                $this->info("   🗑️  Removed: {$migration}");
                $removed++;
            } else {
                $this->error("   ❌ Failed to remove: {$migration}");
            }
        }

        $this->newLine();
        if ($removed > 0) {
            $this->info("🎉 Successfully removed {$removed} MySQL audit migration(s)!");
            $this->line('   Your Laravel app will now use DynamoDB auditing exclusively.');
            $this->newLine();
            
            $this->info('📋 Next steps:');
            $this->line('   1. ✅ Run: php artisan migrate (safe - no audit table conflicts)');
            $this->line('   2. ✅ Your audits will be stored in DynamoDB');
        }

        return self::SUCCESS;
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
