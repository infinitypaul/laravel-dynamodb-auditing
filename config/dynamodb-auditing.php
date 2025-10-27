<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DynamoDB Local Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for local DynamoDB development
    |
    */
    'local' => [
        'credentials' => [
            'key'    => env('DYNAMODB_ACCESS_KEY_ID', 'dummy'),
            'secret' => env('DYNAMODB_SECRET_ACCESS_KEY', 'dummy'),
        ],
        'region'   => env('DYNAMODB_REGION', 'us-east-1'),
        'version'  => 'latest',
        'endpoint' => env('DYNAMODB_ENDPOINT', 'http://localhost:8000'),
    ],

    /*
    |--------------------------------------------------------------------------
    | DynamoDB Production Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for production DynamoDB
    |
    */
    'region' => env('DYNAMODB_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    'version' => 'latest',
    'credentials' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue processing for audit logs to improve performance.
    | When enabled=true, uses Laravel's default queue configuration unless overridden.
    |
    */
    'queue' => [
        'enabled' => env('DYNAMODB_AUDIT_QUEUE_ENABLED', false),
        'connection' => env('DYNAMODB_AUDIT_QUEUE_CONNECTION', null), // null = use default queue connection
        'queue' => env('DYNAMODB_AUDIT_QUEUE_NAME', null), // null = use default queue
    ],

    // Enable GSI for immediate audit visibility (requires manual GSI creation)
    'enable_gsi' => env('DYNAMODB_AUDIT_ENABLE_GSI', false),
    'gsi_only' => env('DYNAMODB_AUDIT_GSI_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Table Configuration
    |--------------------------------------------------------------------------
    |
    | DynamoDB table name and TTL settings
    |
    */
    'table_name' => env('DYNAMODB_AUDIT_TABLE', 'optimus-audit-logs'),
    'ttl_days' => env('DYNAMODB_AUDIT_TTL_DAYS', 730), // 2-year default, set to null for infinite retention
];
