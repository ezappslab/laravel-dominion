<?php

namespace Infinity\Dominion\Services;

use InvalidArgumentException;

class TableRegistry
{
    public function roles(): string
    {
        return $this->table('roles');
    }

    public function permissions(): string
    {
        return $this->table('permissions');
    }

    public function rolePermissions(): string
    {
        return $this->table('role_permissions');
    }

    public function roleAssignments(): string
    {
        return $this->table('role_assignments');
    }

    public function permissionGrants(): string
    {
        return $this->table('permission_grants');
    }

    public function permissionDenials(): string
    {
        return $this->table('permission_denials');
    }

    protected function table(string $key): string
    {
        $table = config("dominion.tables.{$key}");

        if (! is_string($table) || trim($table) === '') {
            throw new InvalidArgumentException("The configured Dominion table [{$key}] must be a non-empty string.");
        }

        return $table;
    }
}
