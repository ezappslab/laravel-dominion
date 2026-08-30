<?php

namespace Infinity\Dominion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Infinity\Dominion\Services\ModelRegistry;
use Infinity\Dominion\Services\TableRegistry;

/** @property string $name */
class Permission extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
    ];

    public function getTable(): string
    {
        return app(TableRegistry::class)->permissions();
    }

    /**
     * Establishes a many-to-many relationship between the current model and the Role model.
     *
     * @return BelongsToMany The relationship instance to interact with the associated roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            app(ModelRegistry::class)->roleModel(),
            app(TableRegistry::class)->rolePermissions(),
        )
            ->withTimestamps();
    }
}
