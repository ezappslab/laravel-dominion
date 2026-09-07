<?php

namespace Infinity\Dominion\Policies;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Facades\Dominion;

/**
 * Supplies convention-based resource permission checks to application policies.
 */
abstract class BasePolicy
{
    /**
     * Resolve a global "resource.action" permission through the facade.
     */
    protected function authorize(Model $principal, Model|string $resource, string $action): bool
    {
        $name = $resource instanceof Model ? $resource->getTable() : $resource;

        return Dominion::for($principal)->globally()->isAllowed("{$name}.{$action}");
    }
}
