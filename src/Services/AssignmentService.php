<?php

namespace Infinity\Dominion\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Infinity\Dominion\Contracts\AuthorizationCache;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Domain\AuthorizationScope;
use InvalidArgumentException;

class AssignmentService
{
    /**
     * Create a new assignment service instance.
     */
    public function __construct(
        protected AuthorizationCatalog $catalog,
        protected AuthorizationCache $cache,
        protected ModelRegistry $models,
    ) {}

    /**
     * Persist an idempotent role assignment for the principal and scope.
     */
    public function assignRole(Model $principal, mixed $role, AuthorizationScope $scope): void
    {
        $roleModel = $this->models->roleModel();
        $roleName = $role instanceof $roleModel ? $role->name : $this->catalog->resolveRole($role);
        $roleId = $role instanceof $roleModel ? $role->getKey() : $roleModel::query()->where('name', $roleName)->value('id');

        if ($roleId === null) {
            throw new InvalidArgumentException("Role [{$roleName}] is not present in the Dominion catalog. Run dominion:sync first.");
        }

        DB::table('role_assignments')->upsert(
            [$this->identity($principal, $scope) + ['role_id' => $roleId] + $this->timestamps()],
            ['role_id', 'principal_type', 'principal_id', 'scope_key'],
            ['updated_at'],
        );
        $this->cache->invalidatePrincipal($principal);
    }

    /**
     * Remove a role assignment without affecting other scopes.
     */
    public function removeRole(Model $principal, mixed $role, AuthorizationScope $scope): void
    {
        $roleModel = $this->models->roleModel();
        $roleName = $role instanceof $roleModel ? $role->name : $this->catalog->resolveRole($role);
        $roleId = $role instanceof $roleModel ? $role->getKey() : $roleModel::query()->where('name', $roleName)->value('id');

        if ($roleId !== null) {
            DB::table('role_assignments')->where($this->identity($principal, $scope) + ['role_id' => $roleId])->delete();
            $this->cache->invalidatePrincipal($principal);
        }
    }

    /**
     * Grant a direct permission to the principal.
     */
    public function grant(Model $principal, mixed $permission, AuthorizationScope $scope): void
    {
        $this->storePermissionEffect(
            'permission_grants',
            'permission_denials',
            $principal,
            $permission,
            $scope,
        );
    }

    /**
     * Persist a denial that takes precedence over grants and roles.
     */
    public function deny(Model $principal, mixed $permission, AuthorizationScope $scope): void
    {
        $this->storePermissionEffect(
            'permission_denials',
            'permission_grants',
            $principal,
            $permission,
            $scope,
        );
    }

    /**
     * Remove both direct effects for a permission in the selected scope.
     */
    public function revoke(Model $principal, mixed $permission, AuthorizationScope $scope): void
    {
        $permissionId = $this->permissionId($permission);

        if ($permissionId !== null) {
            $identity = $this->identity($principal, $scope) + ['permission_id' => $permissionId];
            DB::table('permission_grants')->where($identity)->delete();
            DB::table('permission_denials')->where($identity)->delete();
            $this->cache->invalidatePrincipal($principal);
        }
    }

    /**
     * Apply a configured assignment profile atomically.
     *
     * @param  array{roles?: iterable<mixed>, permissions?: iterable<mixed>, denials?: iterable<mixed>}  $profile
     */
    public function applyProfile(Model $principal, array $profile, AuthorizationScope $scope): void
    {
        DB::transaction(function () use ($principal, $profile, $scope): void {
            foreach ($profile['roles'] ?? [] as $role) {
                $this->assignRole($principal, $role, $scope);
            }
            foreach ($profile['permissions'] ?? [] as $permission) {
                $this->grant($principal, $permission, $scope);
            }
            foreach ($profile['denials'] ?? [] as $permission) {
                $this->deny($principal, $permission, $scope);
            }
        });
    }

    /**
     * Persist a direct permission effect for the principal and scope.
     */
    protected function storePermissionEffect(
        string $table,
        string $oppositeTable,
        Model $principal,
        mixed $permission,
        AuthorizationScope $scope,
    ): void {
        $permissionName = $this->catalog->resolvePermission($permission);
        $permissionId = $this->permissionId($permissionName);

        if ($permissionId === null) {
            throw new InvalidArgumentException("Permission [{$permissionName}] is not present in the Dominion catalog. Run dominion:sync first.");
        }

        $identity = $this->identity($principal, $scope) + ['permission_id' => $permissionId];

        DB::transaction(function () use ($table, $oppositeTable, $identity): void {
            DB::table($oppositeTable)->where($identity)->delete();
            DB::table($table)->upsert(
                [$identity + $this->timestamps()],
                ['permission_id', 'principal_type', 'principal_id', 'scope_key'],
                ['updated_at'],
            );
        });

        $this->cache->invalidatePrincipal($principal);
    }

    /**
     * Resolve the persisted identifier for a permission value.
     */
    protected function permissionId(mixed $permission): int|string|null
    {
        $permissionModel = $this->models->permissionModel();
        $permissionName = $permission instanceof $permissionModel
            ? $permission->name
            : $this->catalog->resolvePermission($permission);

        return $permission instanceof $permissionModel
            ? $permission->getKey()
            : $permissionModel::query()->where('name', $permissionName)->value('id');
    }

    /**
     * Build the scoped database identity for a principal.
     *
     * @return array<string, int|string|null>
     */
    protected function identity(Model $principal, AuthorizationScope $scope): array
    {
        $principalId = $principal->getKey();

        if ($principalId === null) {
            throw new InvalidArgumentException('The principal must exist before roles or permissions can be assigned.');
        }

        return [
            'principal_type' => $principal->getMorphClass(),
            'principal_id' => $principalId,
            'tenant_type' => $scope->tenantType,
            'tenant_id' => $scope->tenantId,
            'scope_key' => $scope->key(),
        ];
    }

    /**
     * Get the timestamps used for assignment writes.
     *
     * @return array{created_at: mixed, updated_at: mixed}
     */
    protected function timestamps(): array
    {
        return ['created_at' => now(), 'updated_at' => now()];
    }
}
