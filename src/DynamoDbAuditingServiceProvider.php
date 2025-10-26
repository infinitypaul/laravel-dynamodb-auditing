<?php

namespace InfinityPaul\LaravelDynamoDbAuditing;

use Illuminate\Support\ServiceProvider;
use OwenIt\Auditing\Facades\Auditor;
use InfinityPaul\LaravelDynamoDbAuditing\Console\Commands\SetupDynamoDbAuditTable;
use InfinityPaul\LaravelDynamoDbAuditing\Console\Commands\TestDynamoDbAudit;
use InfinityPaul\LaravelDynamoDbAuditing\Console\Commands\PreventAuditMigration;
use InfinityPaul\LaravelDynamoDbAuditing\Console\Commands\InstallDynamoDbAuditing;

class DynamoDbAuditingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/dynamodb-auditing.php' => config_path('dynamodb-auditing.php'),
        ], 'dynamodb-auditing-config');

        Auditor::extend('dynamodb', function ($app) {
            return new DynamoDbAuditDriver();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallDynamoDbAuditing::class,
                SetupDynamoDbAuditTable::class,
                TestDynamoDbAudit::class,
                PreventAuditMigration::class,
            ]);
        }
    }

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
}
