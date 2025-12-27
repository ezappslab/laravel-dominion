<?php

namespace Infinity\Dominion\Services;

use BackedEnum;
use Infinity\Dominion\Contracts\PermissionValueResolver;
use UnitEnum;

class DefaultPermissionValueResolver implements PermissionValueResolver
{
    /**
     * Resolve the permission value to a string.
     */
    public function resolve(mixed $permission): string
    {
        if ($permission instanceof BackedEnum) {
            return (string) $permission->value;
        }

        if ($permission instanceof UnitEnum) {
            return $permission->name;
        }

        return (string) $permission;
    }
}
