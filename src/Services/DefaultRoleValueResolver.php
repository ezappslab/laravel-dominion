<?php

namespace Infinity\Dominion\Services;

use BackedEnum;
use Infinity\Dominion\Contracts\RoleValueResolver;
use UnitEnum;

class DefaultRoleValueResolver implements RoleValueResolver
{
    /**
     * Resolve the role value to a string.
     */
    public function resolve(mixed $role): string
    {
        if ($role instanceof BackedEnum) {
            return (string) $role->value;
        }

        if ($role instanceof UnitEnum) {
            return $role->name;
        }

        return (string) $role;
    }
}
