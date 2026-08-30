<?php

namespace Infinity\Dominion\Services;

use Infinity\Dominion\Exceptions\InvalidTableConfiguration;

class TableRegistry
{
    /** @var array<string, string> */
    protected array $tables;

    public function __construct()
    {
        $tables = config('dominion.tables');
        $required = [
            'roles',
            'permissions',
            'role_permissions',
            'role_assignments',
            'permission_grants',
            'permission_denials',
        ];

        if (! is_array($tables)) {
            throw InvalidTableConfiguration::for('the value must be an array.');
        }

        $missing = array_diff($required, array_keys($tables));

        if ($missing !== []) {
            throw InvalidTableConfiguration::for('missing required keys: '.implode(', ', $missing).'.');
        }

        $unknown = array_diff(array_keys($tables), $required);

        if ($unknown !== []) {
            throw InvalidTableConfiguration::for('unsupported keys: '.implode(', ', $unknown).'.');
        }

        foreach ($tables as $key => $table) {
            if (! is_string($table) || trim($table) === '') {
                throw InvalidTableConfiguration::for("[{$key}] must be a non-empty string.");
            }
        }

        if (count($tables) !== count(array_unique($tables))) {
            throw InvalidTableConfiguration::for('every table name must be unique.');
        }

        $this->tables = $tables;
    }

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
        return $this->tables[$key];
    }
}
