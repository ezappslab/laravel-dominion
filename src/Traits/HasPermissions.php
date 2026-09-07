<?php

namespace Infinity\Dominion\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Facades\Dominion;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\PendingAuthorization;
use Infinity\Dominion\Services\Catalog;

/**
 * Exposes direct permission relationships and permission-aware query scopes.
 */
trait HasPermissions
{
    /**
     * Return every directly assigned permission across all scopes and effects.
     */
    public function permissions(): MorphToMany
    {
        return $this->permissionRelationship();
    }

    /**
     * Return directly allowed permissions across every assignment scope.
     */
    public function allowedPermissions(): MorphToMany
    {
        return $this->permissionRelationship('allow');
    }

    /**
     * Return directly denied permissions across every assignment scope.
     */
    public function deniedPermissions(): MorphToMany
    {
        return $this->permissionRelationship('deny');
    }

    /**
     * Determine whether a permission resolves to allow globally or for a tenant.
     */
    public function hasPermission(mixed $permission, ?Model $tenant = null): bool
    {
        return $this->permissionAuthorization($tenant)->isAllowed($permission);
    }

    /**
     * Determine whether a permission resolves to allow globally or for a tenant.
     */
    public function allowsPermission(mixed $permission, ?Model $tenant = null): bool
    {
        return $this->hasPermission($permission, $tenant);
    }

    /**
     * Determine whether a permission resolves to deny globally or for a tenant.
     */
    public function deniesPermission(mixed $permission, ?Model $tenant = null): bool
    {
        return $this->permissionAuthorization($tenant)->isDenied($permission);
    }

    /**
     * Store a direct permission allowance globally or for a tenant.
     */
    public function allowPermission(mixed $permission, ?Model $tenant = null): static
    {
        $this->permissionAuthorization($tenant)->allow($permission);

        return $this;
    }

    /**
     * Store a direct permission denial globally or for a tenant.
     */
    public function denyPermission(mixed $permission, ?Model $tenant = null): static
    {
        $this->permissionAuthorization($tenant)->deny($permission);

        return $this;
    }

    /**
     * Remove a direct permission decision globally or for a tenant.
     */
    public function forgetPermission(mixed $permission, ?Model $tenant = null): static
    {
        $this->permissionAuthorization($tenant)->forget($permission);

        return $this;
    }

    /**
     * Resolve several permissions globally or for a tenant.
     *
     * @return array<string, bool>
     */
    public function checkPermissions(iterable $permissions, ?Model $tenant = null): array
    {
        return $this->permissionAuthorization($tenant)->checkMany($permissions);
    }

    /**
     * Restrict principals using the same specificity cascade as facade checks.
     */
    public function scopeWithPermission(Builder $query, mixed $permission, ?Model $tenant = null): Builder
    {
        $name = app(Catalog::class)->permission($permission);

        $model = $query->getModel();
        $type = $model->getMorphClass();
        $key = $model->getConnection()->getQueryGrammar()->wrap($model->qualifyColumn($model->getKeyName()));
        $assignments = config('dominion.tables.assignments', 'dominion_assignments');
        $permissions = config('dominion.tables.permissions', 'dominion_permissions');
        $rolePermissions = config('dominion.tables.role_permissions', 'dominion_role_permissions');
        $global = AuthorizationScope::global()->key();
        $tenantKey = $tenant ? AuthorizationScope::tenant($tenant)->key() : null;

        // Correlated EXISTS clauses avoid loading principals while preserving precedence.
        $direct = "select 1 from {$assignments} a join {$permissions} p on p.id = a.permission_id where a.principal_type = ? and a.principal_id = {$key} and a.scope_key = ? and p.name = ?";
        $directAllow = $direct." and a.effect = 'allow'";
        $role = "select 1 from {$assignments} a join {$rolePermissions} rp on rp.role_id = a.role_id join {$permissions} p on p.id = rp.permission_id where a.principal_type = ? and a.principal_id = {$key} and a.scope_key = ? and p.name = ?";
        $default = "exists (select 1 from {$permissions} p where p.name = ? and p.default_effect = 'allow')";

        if ($tenantKey === null) {
            $sql = "exists ({$directAllow}) or (not exists ({$direct}) and (exists ({$role}) or {$default}))";

            return $query->whereRaw($sql, [$type, $global, $name, $type, $global, $name, $type, $global, $name, $name]);
        }

        $sql = "exists ({$directAllow})
            or (not exists ({$direct}) and exists ({$directAllow}))
            or (not exists ({$direct}) and not exists ({$direct}) and (exists ({$role}) or exists ({$role}) or {$default}))";

        return $query->whereRaw($sql, [
            $type, $tenantKey, $name,
            $type, $tenantKey, $name, $type, $global, $name,
            $type, $tenantKey, $name, $type, $global, $name,
            $type, $tenantKey, $name, $type, $global, $name, $name,
        ]);
    }

    /**
     * Build the direct permission relationship for one stored effect.
     */
    private function permissionRelationship(?string $effect = null): MorphToMany
    {
        $relationship = $this->morphToMany(
            config('dominion.models.permission', Permission::class),
            'principal',
            config('dominion.tables.assignments', 'dominion_assignments'),
            'principal_id',
            'permission_id',
        )->withPivot(['tenant_type', 'tenant_id', 'scope_key', 'effect'])
            ->withTimestamps();

        return $effect === null ? $relationship : $relationship->wherePivot('effect', $effect);
    }

    /**
     * Build the facade operation used by the permission convenience methods.
     */
    private function permissionAuthorization(?Model $tenant): PendingAuthorization
    {
        $authorization = Dominion::for($this);

        return $tenant === null ? $authorization->globally() : $authorization->in($tenant);
    }
}
