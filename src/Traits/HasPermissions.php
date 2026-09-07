<?php

namespace Infinity\Dominion\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Facades\Dominion;
use Infinity\Dominion\Models\Assignment;
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
        return $this->morphToMany(
            config('dominion.models.permission', Permission::class),
            'principal',
            config('dominion.tables.assignments', 'dominion_assignments'),
            'principal_id',
            'permission_id',
        )->withPivot(['tenant_type', 'tenant_id', 'scope_key', 'effect'])
            ->withTimestamps();
    }

    /**
     * Return directly allowed permissions across every assignment scope.
     */
    public function allowedPermissions(): MorphToMany
    {
        return $this->permissions()->wherePivot('effect', Assignment::EFFECT_ALLOW);
    }

    /**
     * Return directly denied permissions across every assignment scope.
     */
    public function deniedPermissions(): MorphToMany
    {
        return $this->permissions()->wherePivot('effect', Assignment::EFFECT_DENY);
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
        $principalKey = $model->qualifyColumn($model->getKeyName());
        $global = AuthorizationScope::global()->key();
        $tenantKey = $tenant ? AuthorizationScope::tenant($tenant)->key() : null;

        if ($tenantKey === null) {
            return $query->where(function (Builder $decision) use ($model, $principalKey, $global, $name): void {
                $decision->whereExists($this->directPermissionQuery($model, $principalKey, $global, $name, Assignment::EFFECT_ALLOW))
                    ->orWhere(function (Builder $fallback) use ($model, $principalKey, $global, $name): void {
                        $fallback->whereNotExists($this->directPermissionQuery($model, $principalKey, $global, $name))
                            ->where(function (Builder $inherited) use ($model, $principalKey, $global, $name): void {
                                $inherited->whereExists($this->rolePermissionQuery($model, $principalKey, $global, $name))
                                    ->orWhereExists($this->defaultPermissionQuery($name));
                            });
                    });
            });
        }

        return $query->where(function (Builder $decision) use ($model, $principalKey, $tenantKey, $global, $name): void {
            $decision->whereExists($this->directPermissionQuery($model, $principalKey, $tenantKey, $name, Assignment::EFFECT_ALLOW))
                ->orWhere(function (Builder $globalDirect) use ($model, $principalKey, $tenantKey, $global, $name): void {
                    $globalDirect->whereNotExists($this->directPermissionQuery($model, $principalKey, $tenantKey, $name))
                        ->whereExists($this->directPermissionQuery($model, $principalKey, $global, $name, Assignment::EFFECT_ALLOW));
                })
                ->orWhere(function (Builder $inherited) use ($model, $principalKey, $tenantKey, $global, $name): void {
                    $inherited->whereNotExists($this->directPermissionQuery($model, $principalKey, $tenantKey, $name))
                        ->whereNotExists($this->directPermissionQuery($model, $principalKey, $global, $name))
                        ->where(function (Builder $rolesOrDefault) use ($model, $principalKey, $tenantKey, $global, $name): void {
                            $rolesOrDefault->whereExists($this->rolePermissionQuery($model, $principalKey, $tenantKey, $name))
                                ->orWhereExists($this->rolePermissionQuery($model, $principalKey, $global, $name))
                                ->orWhereExists($this->defaultPermissionQuery($name));
                        });
                });
        });
    }

    /**
     * Build a correlated direct-assignment subquery without loading models.
     */
    private function directPermissionQuery(Model $principal, string $principalKey, string $scopeKey, string $permission, ?string $effect = null): Builder
    {
        $assignment = $this->newAssignmentQuery();
        $assignmentTable = $assignment->getModel()->getTable();
        $permissionTable = $this->permissionTable();

        return $assignment->selectRaw('1')
            ->join($permissionTable, "{$permissionTable}.id", '=', "{$assignmentTable}.permission_id")
            ->where("{$assignmentTable}.principal_type", $principal->getMorphClass())
            ->whereColumn("{$assignmentTable}.principal_id", $principalKey)
            ->where("{$assignmentTable}.scope_key", $scopeKey)
            ->where("{$permissionTable}.name", $permission)
            ->when($effect !== null, fn (Builder $query) => $query->where("{$assignmentTable}.effect", $effect));
    }

    /**
     * Build a correlated role-derived permission subquery without loading models.
     */
    private function rolePermissionQuery(Model $principal, string $principalKey, string $scopeKey, string $permission): Builder
    {
        $assignment = $this->newAssignmentQuery();
        $assignmentTable = $assignment->getModel()->getTable();
        $permissionTable = $this->permissionTable();
        $pivotTable = config('dominion.tables.role_permissions', 'dominion_role_permissions');

        return $assignment->selectRaw('1')
            ->join($pivotTable, "{$pivotTable}.role_id", '=', "{$assignmentTable}.role_id")
            ->join($permissionTable, "{$permissionTable}.id", '=', "{$pivotTable}.permission_id")
            ->where("{$assignmentTable}.principal_type", $principal->getMorphClass())
            ->whereColumn("{$assignmentTable}.principal_id", $principalKey)
            ->where("{$assignmentTable}.scope_key", $scopeKey)
            ->where("{$permissionTable}.name", $permission);
    }

    /**
     * Build the materialized default-allow permission subquery.
     */
    private function defaultPermissionQuery(string $permission): Builder
    {
        $class = config('dominion.models.permission', Permission::class);

        return $class::query()->selectRaw('1')->where('name', $permission)->where('default_effect', Assignment::EFFECT_ALLOW);
    }

    /**
     * Start a query using the configured assignment model.
     */
    private function newAssignmentQuery(): Builder
    {
        $class = config('dominion.models.assignment', Assignment::class);

        return $class::query();
    }

    /**
     * Return the table used by the configured permission model.
     */
    private function permissionTable(): string
    {
        $class = config('dominion.models.permission', Permission::class);

        return (new $class)->getTable();
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
