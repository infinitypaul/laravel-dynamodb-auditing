<?php

namespace InfinityPaul\LaravelDynamoDbAuditing\Jobs;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDynamoDbAudit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $timeout = 60;

    public function __construct(
        private array $auditData,
        private string $tableName,
        private array $dynamoDbConfig
    ) {}

    public function handle(): void
    {

        try {
            $dynamoDb = new DynamoDbClient($this->dynamoDbConfig);
            $marshaler = new Marshaler();
            $item = $marshaler->marshalItem($this->auditData);
            $dynamoDb->putItem([
                'TableName' => $this->tableName,
                'Item' => $item,
            ]);
        } catch (DynamoDbException $e) {
            // Calculate item size in bytes (DynamoDB uses JSON serialization for size calculation)
            $itemJson = json_encode($item);
            $itemSize = strlen($itemJson);

            logger()->error('[InfinityPaul\LaravelDynamoDbAuditing\Jobs\ProcessDynamoDbAudit::handle] DynamoDbException: ' . $e->getMessage(), [
                'table'      => $this->tableName,
                'item'       => $item,
                'itemSize'   => $itemSize,
                'itemSizeKB' => round($itemSize / 1024, 2),
                'error'      => $e->getMessage(),
                'exception'  => get_class($e),
                'trace'      => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        } catch (\Exception $e) {

            logger()->error('[InfinityPaul\LaravelDynamoDbAuditing\Jobs\ProcessDynamoDbAudit::handle] Exception: ' . $e->getMessage(), [
                'table'     => $this->tableName,
                'auditData' => $this->auditData,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
                'trace'     => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Silently fail - audit failures should not break the application
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }
}
