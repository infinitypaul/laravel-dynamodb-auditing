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
    | Enable queue processing for better performance in high-traffic applications.
    | Audit writes become non-blocking and are processed asynchronously.
    |
    */
    'queue' => [
        'enabled' => env('DYNAMODB_AUDIT_QUEUE_ENABLED', false),
        'connection' => env('DYNAMODB_AUDIT_QUEUE_CONNECTION', null), // null = use default
        'queue' => env('DYNAMODB_AUDIT_QUEUE_NAME', null), // null = use default
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Configuration
    |--------------------------------------------------------------------------
    |
    | The package uses two optimized query patterns:
    | 1. Primary Key queries for entity-specific lookups (fastest)
    | 2. GSI queries for recent audit browsing (dashboard views)
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Table Configuration
    |--------------------------------------------------------------------------
    |
    | DynamoDB table name and automatic cleanup settings.
    | TTL (Time-To-Live) automatically deletes old audit logs to control costs.
    |
    */
    'table_name' => env('DYNAMODB_AUDIT_TABLE', 'optimus-audit-logs'),
    'ttl_days' => env('DYNAMODB_AUDIT_TTL_DAYS', 730), // Auto-delete after 2 years (null = never delete)
];
