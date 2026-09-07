<?php

namespace Infinity\Dominion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Persists a synchronized role and its configured permission mapping.
 *
 * @property string $name
 */
class Role extends Model
{
    /** @var list<string> */
    protected $fillable = ['name'];

    /**
     * Resolve the configurable role table at runtime.
     */
    public function getTable(): string
    {
        return config('dominion.tables.roles', 'dominion_roles');
    }

    /**
     * Return the permissions materialized for this role by dominion:sync.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(config('dominion.models.permission', Permission::class), config('dominion.tables.role_permissions', 'dominion_role_permissions'));
    }
}
