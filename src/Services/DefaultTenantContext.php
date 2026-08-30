<?php

namespace Infinity\Dominion\Services;

use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Domain\AuthorizationScope;

class DefaultTenantContext implements TenantContext
{
    /**
     * Get the current tenant identifier.
     */
    public function currentScope(): AuthorizationScope
    {
        return AuthorizationScope::global();
    }
}
