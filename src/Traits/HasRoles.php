<?php

namespace Infinity\Dominion\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Facades\Dominion;
use Infinity\Dominion\Models\Role;
use Infinity\Dominion\PendingAuthorization;
use Infinity\Dominion\Services\Catalog;

/**
 * Exposes role relationships and role-aware query scopes on a principal.
 */
trait HasRoles
{
    /**
     * Return every role assignment for the principal across all scopes.
     */
    public function roles(): MorphToMany
    {
        return $this->morphToMany(
            config('dominion.models.role', Role::class),
            'principal',
            config('dominion.tables.assignments', 'dominion_assignments'),
            'principal_id',
            'role_id',
        )->withPivot(['tenant_type', 'tenant_id', 'scope_key', 'effect'])->withTimestamps();
    }

    /**
     * Determine whether the principal has a role globally or for a tenant.
     *
     * Global roles are inherited when a tenant is supplied.
     */
    public function hasRole(mixed $role, ?Model $tenant = null): bool
    {
        return $this->roleAuthorization($tenant)->hasRole($role);
    }

    /**
     * Grant a role globally or directly within the supplied tenant.
     */
    public function grantRole(mixed $role, ?Model $tenant = null): static
    {
        $this->roleAuthorization($tenant)->grant($role);

        return $this;
    }

    /**
     * Revoke a role globally or directly within the supplied tenant.
     */
    public function revokeRole(mixed $role, ?Model $tenant = null): static
    {
        $this->roleAuthorization($tenant)->revoke($role);

        return $this;
    }

    /**
     * Restrict principals to an exact role scope using a correlated query.
     */
    public function scopeWithRole(Builder $query, mixed $role, ?Model $tenant = null): Builder
    {
        $name = app(Catalog::class)->role($role);
        $roleTable = config('dominion.tables.roles', 'dominion_roles');
        $assignments = config('dominion.tables.assignments', 'dominion_assignments');
        $scope = $tenant ? AuthorizationScope::tenant($tenant) : AuthorizationScope::global();

        return $query->whereExists(function ($sub) use ($name, $roleTable, $assignments, $scope): void {
            $sub->selectRaw('1')->from($assignments)->join($roleTable, "{$roleTable}.id", '=', "{$assignments}.role_id")
                ->whereColumn("{$assignments}.principal_id", $this->qualifyColumn($this->getKeyName()))
                ->where("{$assignments}.principal_type", $this->getMorphClass())
                ->where("{$assignments}.scope_key", $scope->key())
                ->where("{$roleTable}.name", $name);
        });
    }

    /**
     * Build the facade operation used by the role convenience methods.
     */
    private function roleAuthorization(?Model $tenant): PendingAuthorization
    {
        $authorization = Dominion::for($this);

        return $tenant === null ? $authorization->globally() : $authorization->in($tenant);
    }
}
