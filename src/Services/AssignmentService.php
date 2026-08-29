<?php

namespace Infinity\Dominion\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Infinity\Dominion\Contracts\AuthorizationCache;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Events\PermissionDenied;
use Infinity\Dominion\Events\PermissionGranted;
use Infinity\Dominion\Events\PermissionRevoked;
use Infinity\Dominion\Events\RoleAssigned;
use Infinity\Dominion\Events\RoleRemoved;
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

        DB::transaction(function () use ($principal, $roleName, $scope, $roleId): void {
            $inserted = DB::table('role_assignments')->insertOrIgnore(
                $this->identity($principal, $scope) + ['role_id' => $roleId] + $this->timestamps(),
            );

            if ($inserted > 0) {
                $this->afterCommit($principal, new RoleAssigned($principal, $roleName, $scope));
            }
        });
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
            DB::transaction(function () use ($principal, $roleName, $scope, $roleId): void {
                $deleted = DB::table('role_assignments')
                    ->where($this->identity($principal, $scope) + ['role_id' => $roleId])
                    ->delete();

                if ($deleted > 0) {
                    $this->afterCommit($principal, new RoleRemoved($principal, $roleName, $scope));
                }
            });
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
            PermissionGranted::class,
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
            PermissionDenied::class,
        );
    }

    /**
     * Remove both direct effects for a permission in the selected scope.
     */
    public function revoke(Model $principal, mixed $permission, AuthorizationScope $scope): void
    {
        $permissionName = $this->catalog->resolvePermission($permission);
        $permissionId = $this->permissionId($permissionName);

        if ($permissionId !== null) {
            $identity = $this->identity($principal, $scope) + ['permission_id' => $permissionId];

            DB::transaction(function () use ($principal, $permissionName, $scope, $identity): void {
                $deleted = DB::table('permission_grants')->where($identity)->delete();
                $deleted += DB::table('permission_denials')->where($identity)->delete();

                if ($deleted > 0) {
                    $this->afterCommit($principal, new PermissionRevoked($principal, $permissionName, $scope));
                }
            });
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
     *
     * @param  class-string<PermissionGranted|PermissionDenied>  $eventClass
     */
    protected function storePermissionEffect(
        string $table,
        string $oppositeTable,
        Model $principal,
        mixed $permission,
        AuthorizationScope $scope,
        string $eventClass,
    ): void {
        $permissionName = $this->catalog->resolvePermission($permission);
        $permissionId = $this->permissionId($permissionName);

        if ($permissionId === null) {
            throw new InvalidArgumentException("Permission [{$permissionName}] is not present in the Dominion catalog. Run dominion:sync first.");
        }

        $identity = $this->identity($principal, $scope) + ['permission_id' => $permissionId];

        DB::transaction(function () use ($table, $oppositeTable, $identity, $principal, $permissionName, $scope, $eventClass): void {
            $changed = DB::table($oppositeTable)->where($identity)->delete() > 0;
            $inserted = DB::table($table)->insertOrIgnore(
                $identity + $this->timestamps(),
            );

            if ($changed || $inserted > 0) {
                $this->afterCommit($principal, new $eventClass($principal, $permissionName, $scope));
            }
        });
    }

    /**
     * Invalidate principal decisions and dispatch an event after commit.
     */
    protected function afterCommit(Model $principal, object $event): void
    {
        DB::afterCommit(function () use ($principal, $event): void {
            $this->cache->invalidatePrincipal($principal);
            Event::dispatch($event);
        });
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
