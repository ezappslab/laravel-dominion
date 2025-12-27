<?php

namespace Infinity\Dominion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'guard_name',
    ];

    /**
     * Establishes a many-to-many relationship between the current model and the Role model.
     *
     * @return BelongsToMany The relationship instance to interact with the associated roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')
            ->withTimestamps();
    }
}
