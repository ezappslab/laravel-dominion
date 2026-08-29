<?php

namespace Infinity\Dominion\Contracts;

interface AuthorizationCatalog
{
    /**
     * Normalize a permission enum, model, or scalar to its catalog name.
     */
    public function resolvePermission(mixed $permission): string;

    /**
     * Normalize a role enum, model, or scalar to its catalog name.
     */
    public function resolveRole(mixed $role): string;

    /**
     * Get the configured permission names.
     *
     * @return list<string>
     */
    public function permissions(): array;

    /**
     * Get the configured role names.
     *
     * @return list<string>
     */
    public function roles(): array;

    /**
     * Get the configured permission names keyed by role name.
     *
     * @return array<string, list<string>>
     */
    public function rolePermissions(): array;
}
