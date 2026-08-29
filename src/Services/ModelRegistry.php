<?php

namespace Infinity\Dominion\Services;

use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use InvalidArgumentException;

class ModelRegistry
{
    /**
     * Resolve the configured role model.
     *
     * @return class-string<Role>
     */
    public function roleModel(): string
    {
        return $this->model('role', Role::class, Role::class);
    }

    /**
     * Resolve the configured permission model.
     *
     * @return class-string<Permission>
     */
    public function permissionModel(): string
    {
        return $this->model('permission', Permission::class, Permission::class);
    }

    /**
     * @template TModel of object
     *
     * @param  class-string<TModel>  $default
     * @param  class-string<TModel>  $base
     * @return class-string<TModel>
     */
    private function model(string $key, string $default, string $base): string
    {
        $model = config("dominion.models.{$key}", $default);

        if (! is_string($model) || ! is_a($model, $base, true)) {
            throw new InvalidArgumentException("The configured Dominion {$key} model must extend {$base}.");
        }

        return $model;
    }
}
