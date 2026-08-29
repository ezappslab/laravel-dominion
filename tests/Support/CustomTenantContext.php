<?php

namespace Tests\Support;

use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Domain\AuthorizationScope;

class CustomTenantContext implements TenantContext
{
    public function currentScope(): AuthorizationScope
    {
        return AuthorizationScope::tenant(123);
    }
}
