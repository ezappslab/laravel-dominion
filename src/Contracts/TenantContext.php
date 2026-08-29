<?php

namespace Infinity\Dominion\Contracts;

use Infinity\Dominion\Domain\AuthorizationScope;

interface TenantContext
{
    /**
     * Resolve the authorization scope for the current request or process.
     */
    public function currentScope(): AuthorizationScope;
}
