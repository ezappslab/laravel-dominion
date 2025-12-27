<?php

namespace Infinity\Dominion\Services;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Contracts\AuthorizationResolver;
use Infinity\Dominion\Contracts\PermissionValueResolver;
use Infinity\Dominion\Models\Permission;

class DefaultAuthorizationResolver implements AuthorizationResolver
{
    /**
     * Determines whether the given model has the specified permission within the context of an optional tenant.
     *
     * @param  Model  $model  The model being checked for the permission.
     * @param  mixed  $permission  The permission to check. Can be an instance of the Permission class,
     *                             a numeric ID, or a string that can be resolved to a permission name.
     * @param  mixed|null  $tenantId  Optional tenant identifier for tenant-scoped permission checks.
     * @return bool True if the permission is granted, false otherwise.
     *
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    public function hasPermission(Model $model, mixed $permission, mixed $tenantId = null): bool
    {
        $authorizationCache = app(AuthorizationCache::class);

        if ($authorizationCache->isEnabled()) {
            $cachedResult = $authorizationCache->get($model, $permission, $tenantId);

            if ($cachedResult !== null) {
                return $cachedResult;
            }
        }

        $result = $this->resolveAndCheckPermission($model, $permission, $tenantId);

        if ($authorizationCache->isEnabled()) {
            $authorizationCache->put($model, $permission, $tenantId, $result);
        }

        return $result;
    }

    /**
     * Internal method to resolve permission ID and perform the actual checks.
     *
     *
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    protected function resolveAndCheckPermission(Model $model, mixed $permission, mixed $tenantId = null): bool
    {
        $permissionId = $this->resolvePermissionId($permission);

        if ($permissionId === null) {
            return false;
        }

        // 1. Explicit Deny (Tenant > Global)
        if ($this->isExplicitlyDenied($model, $permissionId, $tenantId)) {
            return false;
        }

        // 2. Explicit Allow (Tenant > Global)
        if ($this->isExplicitlyAllowed($model, $permissionId, $tenantId)) {
            return true;
        }

        // 3. Role-based Permissions
        if ($this->hasPermissionViaRole($model, $permissionId, $tenantId)) {
            return true;
        }

        return false;
    }

    /**
     * Determines if a specific permission is explicitly denied for a given model.
     *
     * @param  Model  $model  The model on which the permission should be checked.
     * @param  int  $permissionId  The ID of the permission to verify.
     * @param  mixed  $tenantId  The tenant identifier, which may affect the scope of the denial. Can be null for global checks.
     * @return bool True if the permission is explicitly denied, false otherwise.
     */
    protected function isExplicitlyDenied(Model $model, int $permissionId, mixed $tenantId): bool
    {
        if (! method_exists($model, 'deniedPermissions')) {
            return false;
        }

        // Tenant-specific deny
        if ($tenantId !== null && $this->checkPivotExists($model->deniedPermissions(), $permissionId, $tenantId)) {
            return true;
        }

        // Global deny
        return $this->checkPivotExists($model->deniedPermissions(), $permissionId, null);
    }

    /**
     * Determines if a given permission is explicitly allowed for a model, either globally
     * or for a specific tenant.
     *
     * @param  Model  $model  The model instance whose permissions will be checked.
     * @param  int  $permissionId  The ID of the permission to check for.
     * @param  mixed  $tenantId  The tenant identifier. Can be null for checking global permissions.
     * @return bool True if the permission is explicitly allowed, false otherwise.
     */
    protected function isExplicitlyAllowed(Model $model, int $permissionId, mixed $tenantId): bool
    {
        if (! method_exists($model, 'permissions')) {
            return false;
        }

        // Tenant-specific allow
        if ($tenantId !== null && $this->checkPivotExists($model->permissions(), $permissionId, $tenantId)) {
            return true;
        }

        // Global allow
        return $this->checkPivotExists($model->permissions(), $permissionId, null);
    }

    /**
     * Checks if the given model has the specified permission through any of its associated roles
     * within the context of a specific tenant or globally.
     *
     * @param  Model  $model  The model instance to check roles and permissions for.
     * @param  int  $permissionId  The ID of the permission to verify.
     * @param  mixed  $tenantId  The context-specific tenant ID or global context identifier.
     * @return bool True if the model has the permission via any of its roles, false otherwise.
     */
    protected function hasPermissionViaRole(Model $model, int $permissionId, mixed $tenantId): bool
    {
        if (! method_exists($model, 'roles')) {
            return false;
        }

        // Check roles assigned to this model for the given tenant/global context
        // and see if any of those roles have the permission.
        return $model->roles()
            ->wherePivot('tenant_id', $tenantId)
            ->whereHas('permissions', function ($query) use ($permissionId) {
                $query->where('permissions.id', $permissionId);
            })
            ->exists();
    }

    /**
     * Checks if a pivot table entry exists based on the given relationship, permission ID, and tenant ID.
     *
     * @param  mixed  $relationship  The relationship query builder instance to check against.
     * @param  int  $permissionId  The ID of the permission to query in the relationship.
     * @param  mixed  $tenantId  The tenant ID used for filtering the pivot table.
     * @return bool Returns true if the pivot entry exists, otherwise false.
     */
    protected function checkPivotExists($relationship, int $permissionId, mixed $tenantId): bool
    {
        return $relationship
            ->where('permissions.id', $permissionId)
            ->wherePivot('tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Resolves the permission identifier from a given input.
     *
     * @param  mixed  $permission  The input representing a permission. Can be an instance
     *                             of the Permission class, a numeric ID, or a string that can be resolved to a permission name.
     * @return int|null The resolved permission ID or null if not found.
     *
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    protected function resolvePermissionId(mixed $permission): ?int
    {
        if ($permission instanceof Permission) {
            return $permission->id;
        }

        if (is_numeric($permission)) {
            return (int) $permission;
        }

        $permissionName = app(PermissionValueResolver::class)->resolve($permission);

        return Permission::where('name', $permissionName)->first()?->id;
    }
}
