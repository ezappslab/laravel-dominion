<?php

namespace Infinity\Dominion\Contracts;

interface TenantContext
{
    /**
     * Get the current tenant identifier.
     */
    public function getTenantId(): mixed;
}
