<?php

namespace Infinity\Dominion\Services;

use Infinity\Dominion\Contracts\TenantContext;

class DefaultTenantContext implements TenantContext
{
    /**
     * Get the current tenant identifier.
     */
    public function getTenantId(): mixed
    {
        return null;
    }
}
