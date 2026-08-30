<?php

namespace Infinity\Dominion\Events;

use Infinity\Dominion\Models\Role;

final readonly class RolePermissionsSynchronized
{
    /**
     * Create a new role permissions synchronized event.
     *
     * @param  list<int|string|null>  $permissionIds
     */
    public function __construct(
        public Role $role,
        public array $permissionIds,
    ) {}
}
