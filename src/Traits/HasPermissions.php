<?php

namespace Infinity\Dominion\Traits;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Infinity\Dominion\Contracts\AuthorizationResolver;
use Infinity\Dominion\Contracts\PermissionValueResolver;
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Services\AuthorizationCache;
use Workbench\App\Models\User;

trait HasPermissions
{
    /**
     * Defines a polymorphic many-to-many relationship with the Permission model.
     *
     * @return MorphToMany The relationship query builder for the permissions associated with the model.
     */
    public function permissions(): MorphToMany
    {
        return $this->morphToMany(Permission::class, 'permissionable', 'permissionables')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    /**
     * Retrieves the permissions that have been explicitly denied for the current model.
     *
     * @return MorphToMany A morph-to-many relationship instance representing the denied permissions.
     */
    public function deniedPermissions(): MorphToMany
    {
        return $this->morphToMany(Permission::class, 'permissionable', 'denied_permissionables')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    /**
     * Grants the specified permission to the context, optionally scoped to a specific tenant.
     *
     * @param  mixed  $permission  The permission to grant. This can be an instance of the Permission model,
     *                             a numeric value representing the ID, or a reference that can be resolved to a permission name.
     * @param  mixed  $tenantId  Optional. The tenant ID to scope the permission to. If not provided, the current tenant
     *                           from the TenantContext will be used.
     * @return User|HasPermissions Returns the current instance for method chaining.
     *
     * @throws BindingResolutionException If the TenantContext binding cannot be resolved.
     * @throws CircularDependencyException If a circular dependency is detected during resolution.
     */
    public function allow(mixed $permission, mixed $tenantId = null): self
    {
        $permissionId = $this->resolvePermissionId($permission);

        if ($permissionId === null) {
            return $this;
        }

        $tenantId = $tenantId ?? app(TenantContext::class)->getTenantId();

        $this->permissions()->attach($permissionId, [
            'tenant_id' => $tenantId,
        ]);

        app(AuthorizationCache::class)->flushFor($this, $tenantId);

        return $this;
    }

    /**
     * Denies the specified permission for a given tenant context.
     *
     * @param  mixed  $permission  The permission to deny. This can be an instance of the Permission model,
     *                             a numeric value representing the ID, or a reference that can be resolved to a permission name.
     * @param  mixed|null  $tenantId  The ID of the tenant for which the permission is denied. If null, the current tenant context is used.
     * @return User|HasPermissions The current instance for method chaining.
     *
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    public function deny(mixed $permission, mixed $tenantId = null): self
    {
        $permissionId = $this->resolvePermissionId($permission);

        if ($permissionId === null) {
            return $this;
        }

        $tenantId = $tenantId ?? app(TenantContext::class)->getTenantId();

        $this->deniedPermissions()->attach($permissionId, [
            'tenant_id' => $tenantId,
        ]);

        app(AuthorizationCache::class)->flushFor($this, $tenantId);

        return $this;
    }

    /**
     * Determines whether a permission is granted for a tenant-specific or global context.
     *
     * @param  mixed  $permission  The permission to check. This can be an instance of the Permission model,
     *                             a numeric ID, or a reference that can be resolved to a permission name.
     * @param  mixed  $tenantId  The tenant ID for which the permission is evaluated. If null, the tenant ID
     *                           is resolved using the TenantContext.
     * @return bool True if the permission is granted, false otherwise.
     */
    public function hasPermission(mixed $permission, mixed $tenantId = null): bool
    {
        $tenantId = $tenantId ?? app(TenantContext::class)->getTenantId();

        return app(AuthorizationResolver::class)->hasPermission($this, $permission, $tenantId);
    }

    /**
     * Determines whether the given permission is denied for a specific tenant.
     *
     * @param  int  $permissionId  The ID of the permission to check.
     * @param  mixed  $tenantId  The ID of the tenant to evaluate. This can be any value that uniquely identifies a tenant.
     * @return bool True if the permission is denied for the specified tenant, false otherwise.
     */
    protected function isDenied(int $permissionId, mixed $tenantId): bool
    {
        return $this->deniedPermissions()
            ->where('permissions.id', $permissionId)
            ->wherePivot('tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Determines if the given permission is allowed for the specified tenant.
     *
     * @param  int  $permissionId  The ID of the permission to check.
     * @param  mixed  $tenantId  The tenant identifier to check the permission against.
     *                           This can be of any type that represents a tenant.
     * @return bool True if the permission is allowed for the given tenant, false otherwise.
     */
    protected function isAllowed(int $permissionId, mixed $tenantId): bool
    {
        return $this->permissions()
            ->where('permissions.id', $permissionId)
            ->wherePivot('tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Resolves and returns the ID of the given permission.
     *
     * @param  mixed  $permission  The permission to resolve. This can be an instance of the Permission model,
     *                             a numeric value representing the ID, or a reference that can be resolved to a permission name.
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
