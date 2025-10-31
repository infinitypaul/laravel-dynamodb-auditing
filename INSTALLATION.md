# DynamoDB Auditing - Installation Guide

This guide walks you through setting up DynamoDB auditing for a **new Laravel project** from scratch.

## 📋 Prerequisites

- Laravel 10+ application
- AWS account (for production) or Docker (for local development)
- Composer

## 🚀 Quick Setup (2 minutes)

### Option A: One-Command Installation (Recommended)

After installing the package, run the interactive installer:

```bash
# Interactive installer - asks questions and guides you through setup
php artisan audit:install-dynamodb

# For local development (skips some questions)
php artisan audit:install-dynamodb --local

# Force mode (skips all confirmations)
php artisan audit:install-dynamodb --local --force
```

**That's it!** The installer handles everything: migration conflicts, configuration, table creation with GSI, and testing.

---

### Option B: Manual Step-by-Step Installation

If you prefer manual control, follow these steps:

### 1. Install the Package

Install via Composer:

```bash
composer require infinitypaul/laravel-dynamodb-auditing
```

The package will be automatically discovered by Laravel.

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=dynamodb-auditing-config
```

### 3. Prevent MySQL Migration Conflicts

**Important**: Laravel Auditing publishes MySQL migrations that will conflict with DynamoDB. Remove them:

```bash
# Check for conflicting migrations
php artisan audit:prevent-migration --check

# Remove MySQL audit migrations (safe - you're using DynamoDB)
php artisan audit:prevent-migration
```

### 4. Configure Environment

Add to your `.env`:

```env
# Audit Configuration
AUDIT_DRIVER=dynamodb

# DynamoDB Configuration
DYNAMODB_AUDIT_TABLE=your-app-audit-logs
DYNAMODB_AUDIT_TTL_DAYS=730

# For Local Development (with DynamoDB Local)
DYNAMODB_ENDPOINT=http://localhost:8000

# For Production (AWS)
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
```

### 5. Configure Laravel Auditing Driver

Add the DynamoDB driver configuration to your `config/audit.php` file in the `drivers` array:

```php
'drivers' => [
    'database' => [
        'table' => 'audits',
        'connection' => null,
    ],
    'dynamodb' => [
        'table' => env('DYNAMODB_AUDIT_TABLE', 'optimus-audit-logs'),
        'region' => env('DYNAMODB_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    ],
],
```

### 6. Setup DynamoDB

#### Local Development:

First, start DynamoDB Local:
```bash
docker run -p 8000:8000 amazon/dynamodb-local:latest
```

Then create the table with GSI:
```bash
php artisan audit:setup-dynamodb --local
```

#### Production:

```bash
php artisan audit:setup-dynamodb
```

### 7. Configure Your Models

Update your models to use auditing:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class User extends Model implements Auditable
{
    use AuditableTrait;

    // Define which attributes should be audited
    protected $auditInclude = [
        'name',
        'email',
        'is_active',
        'status',
    ];

    // Or exclude specific attributes
    protected $auditExclude = [
        'password',
        'remember_token',
    ];
}
```

### 8. Test the Setup

```bash
php artisan audit:test-dynamodb
```

You should see:
```
🎉 All tests passed! DynamoDB auditing is working correctly.
```

## 🔧 Advanced Configuration

### Custom Table Names

For different environments:

```env
# .env.local
DYNAMODB_AUDIT_TABLE=myapp-audit-logs-local

# .env.staging  
DYNAMODB_AUDIT_TABLE=myapp-audit-logs-staging

# .env.production
DYNAMODB_AUDIT_TABLE=myapp-audit-logs-production
```

### TTL Configuration

```env
# Auto-delete after 1 year
DYNAMODB_AUDIT_TTL_DAYS=365

# Auto-delete after 30 days (for high-volume apps)
DYNAMODB_AUDIT_TTL_DAYS=30

# Keep forever (not recommended for production)
DYNAMODB_AUDIT_TTL_DAYS=null
```

### AWS IAM Permissions

For production, your AWS user/role needs these permissions:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "dynamodb:CreateTable",
                "dynamodb:DescribeTable",
                "dynamodb:PutItem",
                "dynamodb:GetItem",
                "dynamodb:Query",
                "dynamodb:Scan",
                "dynamodb:UpdateTimeToLive",
                "dynamodb:DescribeTimeToLive"
            ],
            "Resource": "arn:aws:dynamodb:*:*:table/your-app-audit-logs*"
        }
    ]
}
```

## 🧪 Testing

### Manual Test

```php
// In tinker or a controller
$user = User::first();
$user->update(['name' => 'Updated Name']);

// Check audit logs
$auditService = app(\InfinityPaul\LaravelDynamoDbAuditing\AuditQueryService::class);
$audits = $auditService->getAllAudits(10);
dd($audits);
```

### Automated Test

```bash
# Test with default User model
php artisan audit:test-dynamodb

# Test with custom model
php artisan audit:test-dynamodb --model="App\Models\Product" --id=1
```

## 🚨 Troubleshooting

### Common Issues

1. **"Table not found"**
   ```bash
   php artisan audit:setup-dynamodb --local --force
   ```

2. **"Driver not registered"**
   - Check `AUDIT_DRIVER=dynamodb` in `.env`
   - Run `composer dump-autoload`

3. **"Connection refused"**
   - Ensure DynamoDB Local is running: `docker ps`
   - Check endpoint: `DYNAMODB_ENDPOINT=http://localhost:8000`

4. **"No audit records created"**
   ```bash
   php artisan audit:test-dynamodb -v
   ```

### Debug Commands

```bash
# Check configuration
php artisan config:show audit
php artisan config:show dynamodb-auditing

# Test connection
php artisan audit:test-dynamodb

# Recreate table
php artisan audit:setup-dynamodb --force
```

## 🎯 Next Steps

1. **Setup Nova Tool** (if using Laravel Nova):
   - Install the companion Nova audit logs tool
   - View audit logs in your admin panel

2. **Production Deployment**:
   - Set up proper AWS IAM roles
   - Configure CloudWatch monitoring
   - Set appropriate TTL values

3. **Performance Optimization**:
   - Consider partitioning strategy for high-volume apps
   - Monitor DynamoDB costs and usage

## 📚 Additional Resources

- [Laravel Auditing Documentation](https://laravel-auditing.com/)
- [AWS DynamoDB Documentation](https://docs.aws.amazon.com/dynamodb/)
- [Package Repository](https://github.com/infinitypaul/laravel-dynamodb-auditing)

---

**Need help?** Open an issue or contact: infinitypaul@live.com
