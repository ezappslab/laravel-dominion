<?php

namespace Infinity\Dominion\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Infinity\Dominion\Contracts\AuthorizationCache;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Events\PermissionDenied;
use Infinity\Dominion\Events\PermissionGranted;
use Infinity\Dominion\Events\PermissionRevoked;
use Infinity\Dominion\Events\RoleAssigned;
use Infinity\Dominion\Events\RoleRemoved;
use Infinity\Dominion\Exceptions\InvalidPrincipal;
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
        protected DominionDatabase $database,
    ) {}

    /**
     * Persist an idempotent role assignment for the principal and scope.
     */
    public function assignRole(Model $principal, mixed $role, AuthorizationScope $scope): void
    {
        $this->database->connection()->transaction(function () use ($principal, $role, $scope): void {
            $event = $this->assignRoleMutation($principal, $role, $scope);
            $this->afterCommit($principal, $event === null ? [] : [$event]);
        });
    }

    /**
     * Remove a role assignment without affecting other scopes.
     */
    public function removeRole(Model $principal, mixed $role, AuthorizationScope $scope): void
    {
        $this->database->connection()->transaction(function () use ($principal, $role, $scope): void {
            $event = $this->removeRoleMutation($principal, $role, $scope);
            $this->afterCommit($principal, $event === null ? [] : [$event]);
        });
    }

    /**
     * Grant a direct permission to the principal.
     */
    public function grant(Model $principal, mixed $permission, AuthorizationScope $scope): void
    {
        $this->database->connection()->transaction(function () use ($principal, $permission, $scope): void {
            $event = $this->permissionEffectMutation(
                'permission_grants',
                'permission_denials',
                $principal,
                $permission,
                $scope,
                PermissionGranted::class,
            );
            $this->afterCommit($principal, $event === null ? [] : [$event]);
        });
    }

    /**
     * Persist a denial that takes precedence over grants and roles.
     */
    public function deny(Model $principal, mixed $permission, AuthorizationScope $scope): void
    {
        $this->database->connection()->transaction(function () use ($principal, $permission, $scope): void {
            $event = $this->permissionEffectMutation(
                'permission_denials',
                'permission_grants',
                $principal,
                $permission,
                $scope,
                PermissionDenied::class,
            );
            $this->afterCommit($principal, $event === null ? [] : [$event]);
        });
    }

    /**
     * Remove both direct effects for a permission in the selected scope.
     */
    public function revoke(Model $principal, mixed $permission, AuthorizationScope $scope): void
    {
        $this->database->connection()->transaction(function () use ($principal, $permission, $scope): void {
            $event = $this->revokePermissionMutation($principal, $permission, $scope);
            $this->afterCommit($principal, $event === null ? [] : [$event]);
        });
    }

    /**
     * Apply a configured assignment profile atomically.
     *
     * @param  array{roles?: iterable<mixed>, permissions?: iterable<mixed>, denials?: iterable<mixed>}  $profile
     */
    public function applyProfile(Model $principal, array $profile, AuthorizationScope $scope): void
    {
        $this->database->connection()->transaction(function () use ($principal, $profile, $scope): void {
            $events = [];

            foreach ($profile['roles'] ?? [] as $role) {
                $events[] = $this->assignRoleMutation($principal, $role, $scope);
            }
            foreach ($profile['permissions'] ?? [] as $permission) {
                $events[] = $this->permissionEffectMutation(
                    'permission_grants',
                    'permission_denials',
                    $principal,
                    $permission,
                    $scope,
                    PermissionGranted::class,
                );
            }
            foreach ($profile['denials'] ?? [] as $permission) {
                $events[] = $this->permissionEffectMutation(
                    'permission_denials',
                    'permission_grants',
                    $principal,
                    $permission,
                    $scope,
                    PermissionDenied::class,
                );
            }

            $this->afterCommit($principal, array_values(array_filter($events)));
        });
    }

    /**
     * Remove every assignment owned by a permanently deleted principal.
     */
    public function purgePrincipal(Model $principal): void
    {
        $principalId = $principal->getKey();

        if (! $principal->exists || $principalId === null) {
            throw InvalidPrincipal::notPersisted($principal);
        }

        $identity = [
            'principal_type' => $principal->getMorphClass(),
            'principal_id' => $principalId,
        ];
        $cachePrincipal = clone $principal;

        $this->database->connection()->transaction(function () use ($identity, $cachePrincipal): void {
            foreach (['role_assignments', 'permission_grants', 'permission_denials'] as $table) {
                $this->database->connection()->table($table)->where($identity)->delete();
            }

            $this->database->connection()->afterCommit(
                fn () => $this->cache->invalidatePrincipal($cachePrincipal),
            );
        });
    }

    /**
     * Persist a role assignment without opening a transaction.
     */
    protected function assignRoleMutation(Model $principal, mixed $role, AuthorizationScope $scope): ?RoleAssigned
    {
        $roleModel = $this->models->roleModel();
        $roleName = $role instanceof $roleModel ? $role->name : $this->catalog->resolveRole($role);
        $roleId = $role instanceof $roleModel ? $role->getKey() : $roleModel::query()->where('name', $roleName)->value('id');

        if ($roleId === null) {
            throw new InvalidArgumentException("Role [{$roleName}] is not present in the Dominion catalog. Run dominion:sync first.");
        }

        $inserted = $this->database->connection()->table('role_assignments')->insertOrIgnore(
            $this->identity($principal, $scope) + ['role_id' => $roleId] + $this->timestamps(),
        );

        return $inserted > 0 ? new RoleAssigned($principal, $roleName, $scope) : null;
    }

    /**
     * Remove a role assignment without opening a transaction.
     */
    protected function removeRoleMutation(Model $principal, mixed $role, AuthorizationScope $scope): ?RoleRemoved
    {
        $roleModel = $this->models->roleModel();
        $roleName = $role instanceof $roleModel ? $role->name : $this->catalog->resolveRole($role);
        $roleId = $role instanceof $roleModel ? $role->getKey() : $roleModel::query()->where('name', $roleName)->value('id');

        if ($roleId === null) {
            return null;
        }

        $deleted = $this->database->connection()->table('role_assignments')
            ->where($this->identity($principal, $scope) + ['role_id' => $roleId])
            ->delete();

        return $deleted > 0 ? new RoleRemoved($principal, $roleName, $scope) : null;
    }

    /**
     * Persist a direct permission effect for the principal and scope.
     *
     * @param  class-string<PermissionGranted|PermissionDenied>  $eventClass
     */
    protected function permissionEffectMutation(
        string $table,
        string $oppositeTable,
        Model $principal,
        mixed $permission,
        AuthorizationScope $scope,
        string $eventClass,
    ): PermissionGranted|PermissionDenied|null {
        $permissionName = $this->catalog->resolvePermission($permission);
        $permissionId = $this->permissionId($permissionName);

        if ($permissionId === null) {
            throw new InvalidArgumentException("Permission [{$permissionName}] is not present in the Dominion catalog. Run dominion:sync first.");
        }

        $identity = $this->identity($principal, $scope) + ['permission_id' => $permissionId];

        $connection = $this->database->connection();
        $changed = $connection->table($oppositeTable)->where($identity)->delete() > 0;
        $inserted = $connection->table($table)->insertOrIgnore($identity + $this->timestamps());

        return $changed || $inserted > 0
            ? new $eventClass($principal, $permissionName, $scope)
            : null;
    }

    /**
     * Remove direct permission effects without opening a transaction.
     */
    protected function revokePermissionMutation(Model $principal, mixed $permission, AuthorizationScope $scope): ?PermissionRevoked
    {
        $permissionName = $this->catalog->resolvePermission($permission);
        $permissionId = $this->permissionId($permissionName);

        if ($permissionId === null) {
            return null;
        }

        $identity = $this->identity($principal, $scope) + ['permission_id' => $permissionId];
        $connection = $this->database->connection();
        $deleted = $connection->table('permission_grants')->where($identity)->delete();
        $deleted += $connection->table('permission_denials')->where($identity)->delete();

        return $deleted > 0 ? new PermissionRevoked($principal, $permissionName, $scope) : null;
    }

    /**
     * Invalidate principal decisions and dispatch an event after commit.
     *
     * @param  list<object>  $events
     */
    protected function afterCommit(Model $principal, array $events): void
    {
        if ($events === []) {
            return;
        }

        $this->database->connection()->afterCommit(function () use ($principal, $events): void {
            $this->cache->invalidatePrincipal($principal);

            foreach ($events as $event) {
                Event::dispatch($event);
            }
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

        if (! $principal->exists || $principalId === null) {
            throw InvalidPrincipal::notPersisted($principal);
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
