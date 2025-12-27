<?php

namespace Infinity\Dominion\Traits;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Infinity\Dominion\Contracts\RoleValueResolver;
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Models\Role;
use Infinity\Dominion\Services\AuthorizationCache;
use Workbench\App\Models\User;

trait HasRoles
{
    /**
     * Defines a polymorphic many-to-many relationship between the current instance and roles.
     *
     * @return MorphToMany A relationship object representing the associated roles.
     */
    public function roles(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'roleable', 'roleables')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    /**
     * Assigns a role to the current instance with an optional tenant context.
     *
     * @param  mixed  $role  The role to be added. It can be an identifier or an object representing the role.
     * @param  mixed|null  $tenantId  The tenant identifier. If not provided, the current tenant context will be used.
     * @return User|HasRoles Returns the current instance for method chaining.
     *
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    public function addRole(mixed $role, mixed $tenantId = null): self
    {
        $roleId = $this->resolveRoleId($role);
        $tenantId = $tenantId ?? app(TenantContext::class)->getTenantId();

        $this->roles()->attach($roleId, [
            'tenant_id' => $tenantId,
        ]);

        app(AuthorizationCache::class)->flushFor($this, $tenantId);

        return $this;
    }

    /**
     * Remove the given role from the model.
     *
     * @param  mixed  $role  The role to be removed. Can be a role instance, ID, or name.
     * @param  mixed|null  $tenantId  The identifier of the tenant context. If null, the current tenant context is used.
     * @return User|HasRoles The current instance of the model, for method chaining.
     *
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    public function removeRole(mixed $role, mixed $tenantId = null): self
    {
        $roleId = $this->resolveRoleId($role);
        $tenantId = $tenantId ?? app(TenantContext::class)->getTenantId();

        $this->roles()
            ->wherePivot('tenant_id', $tenantId)
            ->detach($roleId);

        app(AuthorizationCache::class)->flushFor($this, $tenantId);

        return $this;
    }

    /**
     * Check if the model has the specified role.
     *
     * @param  mixed  $role  The role to check, which can be the role's identifier or instance.
     * @param  mixed  $tenantId  The tenant identifier. If null, the current tenant context is used.
     * @return bool Returns true if the model has the specified role, otherwise false.
     *
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    public function hasRole(mixed $role, mixed $tenantId = null): bool
    {
        $roleId = $this->resolveRoleId($role);
        $tenantId = $tenantId ?? app(TenantContext::class)->getTenantId();

        return $this->roles()
            ->where('roles.id', $roleId)
            ->wherePivot('tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Resolves the role identifier from the provided role input.
     *
     * @param  mixed  $role  The role input, which can be a Role instance, a numeric ID, or a role name.
     * @return int The resolved role ID.
     *
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    protected function resolveRoleId(mixed $role): int
    {
        if ($role instanceof Role) {
            return $role->id;
        }

        if (is_numeric($role)) {
            return (int) $role;
        }

        $roleName = app(RoleValueResolver::class)->resolve($role);

        return Role::where('name', $roleName)->firstOrFail()->id;
    }
}
