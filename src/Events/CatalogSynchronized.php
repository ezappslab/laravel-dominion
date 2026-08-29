<?php

namespace Infinity\Dominion\Events;

final readonly class CatalogSynchronized
{
    /**
     * Create a new catalog synchronized event.
     *
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     * @param  array<string, list<string>>  $rolePermissions
     */
    public function __construct(
        public array $roles,
        public array $permissions,
        public array $rolePermissions,
        public bool $pruned,
    ) {}
}
