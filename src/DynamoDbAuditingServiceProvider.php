<?php

namespace InfinityPaul\LaravelDynamoDbAuditing;

use Illuminate\Support\ServiceProvider;
use OwenIt\Auditing\Facades\Auditor;
use InfinityPaul\LaravelDynamoDbAuditing\Console\Commands\SetupDynamoDbAuditTable;
use InfinityPaul\LaravelDynamoDbAuditing\Console\Commands\TestDynamoDbAudit;
use InfinityPaul\LaravelDynamoDbAuditing\Console\Commands\PreventAuditMigration;
use InfinityPaul\LaravelDynamoDbAuditing\Console\Commands\InstallDynamoDbAuditing;
use \InfinityPaul\LaravelDynamoDbAuditing\Console\Commands\CreateAuditDateIndex;

class DynamoDbAuditingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/dynamodb-auditing.php',
            'dynamodb-auditing'
        );

        $this->app->singleton(AuditQueryService::class, function ($app) {
            return new AuditQueryService();
        });
    }
    
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/dynamodb-auditing.php' => config_path('dynamodb-auditing.php'),
        ], 'dynamodb-auditing-config');

        $this->app->singleton(\OwenIt\Auditing\Contracts\Auditor::class, function ($app) {
            $auditor = new CustomAuditor($app);
            
            $auditor->extend('dynamodb', function ($app) {
                return new DynamoDbAuditDriver();
            });
            
            return $auditor;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallDynamoDbAuditing::class,
                SetupDynamoDbAuditTable::class,
                TestDynamoDbAudit::class,
                PreventAuditMigration::class,
                CreateAuditDateIndex::class,
            ]);
        }
    }
}
