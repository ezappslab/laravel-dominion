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
     * Determine whether a permission belongs to the configured enum catalog.
     */
    public function containsPermission(string $permission): bool;

    /** @return list<string> */
    public function permissions(): array;

    /** @return list<string> */
    public function roles(): array;

    /** @return array<string, list<string>> */
    public function rolePermissions(): array;
}
