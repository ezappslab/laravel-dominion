<?php

namespace Infinity\Dominion\Services;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Contracts\AuthorizationCache;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Contracts\AuthorizationResolver;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Exceptions\InvalidPrincipal;

class DefaultAuthorizationResolver implements AuthorizationResolver
{
    /**
     * Create a new authorization resolver instance.
     */
    public function __construct(
        protected AuthorizationCatalog $catalog,
        protected AuthorizationCache $cache,
        protected ModelRegistry $models,
        protected DominionDatabase $database,
        protected TableRegistry $tables,
    ) {}

    /**
     * Resolve and cache the authorization decision for a permission.
     */
    public function decide(Model $model, mixed $permission, AuthorizationScope $scope): AuthorizationDecision
    {
        if (! $model->exists || $model->getKey() === null) {
            throw InvalidPrincipal::notPersisted($model);
        }

        $permissionName = $this->catalog->resolvePermission($permission);
        $cached = $this->cache->get($model, $permissionName, $scope);

        if ($cached !== null) {
            return $cached;
        }

        $decision = $this->resolve($model, $permissionName, $scope);
        $this->cache->put($model, $permissionName, $scope, $decision);

        return $decision;
    }

    /**
     * Determine whether the principal is allowed the permission.
     */
    public function hasPermission(Model $model, mixed $permission, AuthorizationScope $scope): bool
    {
        return $this->decide($model, $permission, $scope) === AuthorizationDecision::Allow;
    }

    /**
     * Resolve a decision from direct and role-based assignments.
     */
    protected function resolve(Model $principal, string $permission, AuthorizationScope $scope): AuthorizationDecision
    {
        $permissionModel = $this->models->permissionModel();
        $model = new $permissionModel;
        $connection = $this->database->connection();
        $permissionTable = $model->getTable();
        $identity = [
            'principal_type' => $principal->getMorphClass(),
            'principal_id' => $principal->getKey(),
        ];
        $scopeKeys = $this->scopeKeys($scope);
        $denialsTable = $this->tables->permissionDenials();
        $grantsTable = $this->tables->permissionGrants();
        $roleAssignmentsTable = $this->tables->roleAssignments();
        $rolePermissionsTable = $this->tables->rolePermissions();
        $known = $connection->table($permissionTable)
            ->selectRaw('0 as precedence')
            ->where("{$permissionTable}.name", $permission);
        $denials = $connection->table($denialsTable)
            ->join($permissionTable, "{$permissionTable}.id", '=', "{$denialsTable}.permission_id")
            ->selectRaw('3 as precedence')
            ->where($identity)
            ->whereIn("{$denialsTable}.scope_key", $scopeKeys)
            ->where("{$permissionTable}.name", $permission);
        $grants = $connection->table($grantsTable)
            ->join($permissionTable, "{$permissionTable}.id", '=', "{$grantsTable}.permission_id")
            ->selectRaw('2 as precedence')
            ->where($identity)
            ->whereIn("{$grantsTable}.scope_key", $scopeKeys)
            ->where("{$permissionTable}.name", $permission);
        $roles = $connection->table($roleAssignmentsTable)
            ->join($rolePermissionsTable, "{$rolePermissionsTable}.role_id", '=', "{$roleAssignmentsTable}.role_id")
            ->join($permissionTable, "{$permissionTable}.id", '=', "{$rolePermissionsTable}.permission_id")
            ->selectRaw('1 as precedence')
            ->where($identity)
            ->whereIn("{$roleAssignmentsTable}.scope_key", $scopeKeys)
            ->where("{$permissionTable}.name", $permission);

        $precedence = $connection->query()
            ->fromSub($known->unionAll($denials)->unionAll($grants)->unionAll($roles), 'authorization_effects')
            ->max('precedence');

        if ($precedence === null) {
            return AuthorizationDecision::Deny;
        }

        return match ((int) $precedence) {
            3 => AuthorizationDecision::Deny,
            2, 1 => AuthorizationDecision::Allow,
            0 => AuthorizationDecision::Deny,
            default => AuthorizationDecision::Deny,
        };
    }

    /**
     * Get the applicable scope keys for an authorization check.
     *
     * @return list<string>
     */
    protected function scopeKeys(AuthorizationScope $scope): array
    {
        if ($scope->isGlobal()) {
            return [$scope->key()];
        }

        if ((bool) config('dominion.tenancy.global_inherits_into_tenant', true)) {
            return [$scope->key(), AuthorizationScope::global()->key()];
        }

        return [$scope->key()];
    }
}
