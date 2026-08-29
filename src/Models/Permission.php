<?php

namespace Infinity\Dominion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Infinity\Dominion\Services\ModelRegistry;

/** @property string $name */
class Permission extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
    ];

    /**
     * Establishes a many-to-many relationship between the current model and the Role model.
     *
     * @return BelongsToMany The relationship instance to interact with the associated roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(app(ModelRegistry::class)->roleModel(), 'permission_role')
            ->withTimestamps();
    }
}
