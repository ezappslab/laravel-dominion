<?php

namespace Infinity\Dominion\Traits;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Domain\AuthorizationScope;

trait ResolvesAuthorizationScope
{
    /**
     * Normalize an explicit tenant value or resolve the current request scope.
     */
    protected function authorizationScope(AuthorizationScope|Model|string|int|null $tenant = null): AuthorizationScope
    {
        if ($tenant instanceof AuthorizationScope) {
            return $tenant;
        }

        if ($tenant === null) {
            return app(TenantContext::class)->currentScope();
        }

        return AuthorizationScope::tenant($tenant);
    }
}
