<?php

namespace Infinity\Dominion\Domain;

final readonly class AuthorizationCatalogSnapshot
{
    /**
     * Create a new validated authorization catalog snapshot.
     *
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     * @param  array<string, list<string>>  $rolePermissions
     */
    public function __construct(
        public array $roles,
        public array $permissions,
        public array $rolePermissions,
    ) {}
}
