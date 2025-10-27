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
    | Table Configuration
    |--------------------------------------------------------------------------
    |
    | DynamoDB table name and TTL settings
    |
    */
    'table_name' => env('DYNAMODB_AUDIT_TABLE', 'optimus-audit-logs'),
    'ttl_days' => env('DYNAMODB_AUDIT_TTL_DAYS', 730), // 2-year default, set to null for infinite retention
];
