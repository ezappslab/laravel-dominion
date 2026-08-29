<?php

namespace Infinity\Dominion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Event;
use Infinity\Dominion\Contracts\AuthorizationCache;
use Infinity\Dominion\Events\RolePermissionsSynchronized;
use Infinity\Dominion\Services\DominionDatabase;
use Infinity\Dominion\Services\ModelRegistry;

/** @property string $name */
class Role extends Model
{
    /**
     * An array of attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
    ];

    /**
     * Establishes a many-to-many relationship between roles and permissions.
     *
     * @return BelongsToMany The relationship query builder for permissions associated with a role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(app(ModelRegistry::class)->permissionModel(), 'permission_role')
            ->withTimestamps();
    }

    /**
     * Synchronize the permissions assigned to this role.
     *
     * @param  iterable<int, int|Permission>  $permissions
     */
    public function syncPermissions(iterable $permissions): self
    {
        $ids = [];

        foreach ($permissions as $permission) {
            $ids[] = $permission instanceof Permission ? $permission->getKey() : $permission;
        }

        $connection = app(DominionDatabase::class)->connection();

        $connection->transaction(function () use ($ids, $connection): void {
            $this->permissions()->sync($ids);
            $connection->afterCommit(function () use ($ids): void {
                app(AuthorizationCache::class)->invalidateCatalog();
                Event::dispatch(new RolePermissionsSynchronized($this, $ids));
            });
        });

        return $this;
    }
}
