<?php

namespace InfinityPaul\LaravelDynamoDbAuditing;

use OwenIt\Auditing\Auditor;

class CustomAuditor extends Auditor
{
    /**
     * Get the default driver name.
     * 
     * Override to read from config instead of hardcoded 'database'
     */
    public function getDefaultDriver()
    {
        return config('audit.driver', 'database');
    }
}
