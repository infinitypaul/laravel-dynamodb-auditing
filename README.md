# Laravel DynamoDB Auditing

A DynamoDB driver for the [Laravel Auditing](https://github.com/owen-it/laravel-auditing) package, allowing you to store audit logs in AWS DynamoDB instead of a traditional database.

## Features

-  **High Performance**: Store audit logs in DynamoDB for better scalability
-  **Auto-scaling**: DynamoDB handles scaling automatically
-  **TTL Support**: Automatic cleanup of old audit logs
-  **Flexible Schema**: NoSQL structure for varying audit data
-  **Query Service**: Built-in service for querying audit logs
-  **Laravel Integration**: Seamless integration with Laravel Auditing

## Installation

### 1. Install via Composer

```bash
composer require infinitypaul/laravel-dynamodb-auditing
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=dynamodb-auditing-config
```

### 3. Configure Environment Variables

Add the following to your `.env` file:

```env
# Enable DynamoDB auditing
AUDIT_DRIVER=dynamodb

# DynamoDB Configuration
DYNAMODB_AUDIT_TABLE=your-audit-table-name
DYNAMODB_AUDIT_TTL_DAYS=730

# AWS Credentials (production)
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1

# Local Development (optional)
DYNAMODB_ENDPOINT=http://localhost:8000
DYNAMODB_ACCESS_KEY_ID=dummy
DYNAMODB_SECRET_ACCESS_KEY=dummy
```

### 4. Update Audit Configuration

In your `config/audit.php`, set the driver:

```php
'driver' => env('AUDIT_DRIVER', 'dynamodb'),
```

## DynamoDB Table Structure

The package uses the following DynamoDB table structure:

### Primary Key
- **Partition Key (PK)**: `USER#{user_id}` or `{auditable_type}#{auditable_id}`
- **Sort Key (SK)**: `{timestamp}#{event}#{audit_id}`

### Attributes
- `audit_id` - Unique identifier for the audit
- `user_id` - ID of the user who performed the action
- `event` - Type of event (created, updated, deleted, etc.)
- `auditable_type` - Model class name
- `auditable_id` - Model ID
- `old_values` - JSON of old values
- `new_values` - JSON of new values
- `url` - Request URL
- `ip_address` - User's IP address
- `user_agent` - User's browser/client
- `created_at` - Timestamp
- `TTL` - Time-to-live for automatic cleanup

## Setup

### 🚀 Quick Setup (Recommended)

**One command does everything:**

```bash
# Interactive installer - handles complete setup
php artisan audit:install-dynamodb

# For local development
php artisan audit:install-dynamodb --local

# Skip all confirmations
php artisan audit:install-dynamodb --local --force
```

The installer automatically:
- ✅ Checks for migration conflicts
- ✅ Publishes configuration
- ✅ Creates DynamoDB table  
- ✅ Tests the installation
- ✅ Provides next steps

### Manual Setup (Advanced)

If you prefer step-by-step control:

#### 1. Create DynamoDB Table

```bash
# Setup local DynamoDB table (requires DynamoDB Local running on port 8000)
php artisan audit:setup-dynamodb --local

# Or force recreate if table exists
php artisan audit:setup-dynamodb --local --force
```

#### 2. Test the Setup

```bash
# Test DynamoDB audit functionality
php artisan audit:test-dynamodb

# Test with specific model
php artisan audit:test-dynamodb --model="App\Models\User" --id=1
```

## Usage

### Basic Usage

Once configured, the package works automatically with Laravel Auditing:

```php
use OwenIt\Auditing\Contracts\Auditable;

class User extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    
    // Your model code
}
```

### Querying Audit Logs

Use the provided `AuditQueryService`:

```php
use InfinityPaul\LaravelDynamoDbAuditing\AuditQueryService;

$auditService = app(AuditQueryService::class);

// Get all audits with pagination
$result = $auditService->getAllAudits(
    limit: 25,
    lastEvaluatedKey: null,
    filters: [
        'user_id' => 123,
        'event' => 'updated',
        'entity_type' => 'App\\Models\\User'
    ]
);

// Get specific audit by ID
$audit = $auditService->getAuditById('audit_12345');
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `AUDIT_DRIVER` | Audit driver to use | `database` |
| `DYNAMODB_AUDIT_TABLE` | DynamoDB table name | `optimus-audit-logs` |
| `DYNAMODB_AUDIT_TTL_DAYS` | Days before auto-deletion (null = infinite) | `730` |
| `DYNAMODB_ENDPOINT` | Local DynamoDB endpoint | `null` |
| `AWS_ACCESS_KEY_ID` | AWS access key | Required for production |
| `AWS_SECRET_ACCESS_KEY` | AWS secret key | Required for production |
| `AWS_DEFAULT_REGION` | AWS region | `us-east-1` |

### Configuration File

The `config/dynamodb-auditing.php` file allows you to customize:

- AWS credentials and region
- Table name and TTL settings
- Local vs production configurations

### TTL (Time-To-Live) Configuration

Control automatic cleanup of audit logs:

```env
# Auto-delete after 2 years (default)
DYNAMODB_AUDIT_TTL_DAYS=730

# Auto-delete after 1 year
DYNAMODB_AUDIT_TTL_DAYS=365

# Auto-delete after 30 days
DYNAMODB_AUDIT_TTL_DAYS=30

# Infinite retention (never auto-delete)
DYNAMODB_AUDIT_TTL_DAYS=null
```

**Important**: Setting `DYNAMODB_AUDIT_TTL_DAYS=null` will keep audit logs forever, which may increase storage costs over time.

## Performance Considerations

### Why DynamoDB for Audit Logs?

**DynamoDB excels at audit log storage because:**

-  **Consistent Performance**: O(1) read/write operations regardless of table size
-  **Horizontal Scaling**: Automatically scales to handle millions of records
-  **Efficient Queries**: Partition + Sort key design enables fast lookups
-  **No Performance Degradation**: Unlike SQL databases, performance doesn't degrade with table growth

## Testing

Run the package tests:

```bash
composer test
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Submit a pull request

## Commands

The package provides several Artisan commands for setup and testing:

| Command | Description | Options |
|---------|-------------|---------|
| `audit:install-dynamodb` | **🚀 Interactive installer - complete setup** | `--local`, `--production`, `--force` |
| `audit:setup-dynamodb` | Create DynamoDB table for audit logs | `--local`, `--force` |
| `audit:test-dynamodb` | Test DynamoDB audit functionality | `--model`, `--id` |
| `audit:prevent-migration` | Remove conflicting MySQL audit migrations | `--check` |

### Command Examples

```bash
# 🚀 RECOMMENDED: Interactive installer (does everything)
php artisan audit:install-dynamodb --local

# Individual commands (if you prefer manual control)
php artisan audit:setup-dynamodb --local
php artisan audit:test-dynamodb
php artisan audit:prevent-migration --check

# Advanced usage
php artisan audit:setup-dynamodb --force  # Force recreate table
php artisan audit:test-dynamodb --model="App\Models\Product" --id=5
php artisan audit:prevent-migration       # Remove MySQL migrations
```

## Repository & Support

- 📦 **GitHub Repository**: [https://github.com/infinitypaul/laravel-dynamodb-auditing](https://github.com/infinitypaul/laravel-dynamodb-auditing)
- 📚 **Documentation**: Available in the repository
- 🐛 **Issues & Bug Reports**: [GitHub Issues](https://github.com/infinitypaul/laravel-dynamodb-auditing/issues)
- 💬 **Feature Requests**: [GitHub Issues](https://github.com/infinitypaul/laravel-dynamodb-auditing/issues)

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
