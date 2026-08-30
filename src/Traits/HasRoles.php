<?php

namespace Infinity\Dominion\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Services\AssignmentService;
use Infinity\Dominion\Services\ModelRegistry;

trait HasRoles
{
    use CleansDominionAssignments;
    use ResolvesAuthorizationScope;

    /**
     * Get every role assigned to this principal across all scopes.
     */
    public function roles(): MorphToMany
    {
        return $this->morphToMany(app(ModelRegistry::class)->roleModel(), 'principal', 'role_assignments')
            ->withPivot(['tenant_type', 'tenant_id', 'scope_key'])
            ->withTimestamps();
    }

    /**
     * Assign a role in the current, explicit tenant, or global scope.
     */
    public function assignRole(mixed $role, AuthorizationScope|Model|string|int|null $tenant = null): self
    {
        app(AssignmentService::class)->assignRole($this, $role, $this->authorizationScope($tenant));

        return $this;
    }

    /**
     * Backward-compatible alias for assigning a role.
     */
    public function addRole(mixed $role, AuthorizationScope|Model|string|int|null $tenant = null): self
    {
        return $this->assignRole($role, $tenant);
    }

    /**
     * Remove a role from the selected scope.
     */
    public function removeRole(mixed $role, AuthorizationScope|Model|string|int|null $tenant = null): self
    {
        app(AssignmentService::class)->removeRole($this, $role, $this->authorizationScope($tenant));

        return $this;
    }

    /**
     * Determine whether the role applies in the selected scope.
     */
    public function hasRole(mixed $role, AuthorizationScope|Model|string|int|null $tenant = null): bool
    {
        $scope = $this->authorizationScope($tenant);
        $scopeKeys = [$scope->key()];

        if (! $scope->isGlobal() && (bool) config('dominion.tenancy.global_inherits_into_tenant', true)) {
            $scopeKeys[] = AuthorizationScope::global()->key();
        }

        $roleName = app(AuthorizationCatalog::class)->resolveRole($role);

        return $this->roles()
            ->where('roles.name', $roleName)
            ->wherePivotIn('scope_key', $scopeKeys)
            ->exists();
    }
}
