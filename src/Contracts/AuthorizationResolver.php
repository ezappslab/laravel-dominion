<?php

namespace Infinity\Dominion\Contracts;

use Illuminate\Database\Eloquent\Model;

interface AuthorizationResolver
{
    /**
     * Determine if the given model has the specified permission.
     */
    public function hasPermission(Model $model, mixed $permission, mixed $tenantId = null): bool;
}
