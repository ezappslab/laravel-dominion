<?php

namespace Infinity\Dominion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /**
     * An array of attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'guard_name',
    ];

    /**
     * Establishes a many-to-many relationship between roles and permissions.
     *
     * @return BelongsToMany The relationship query builder for permissions associated with a role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')
            ->withTimestamps();
    }
}
