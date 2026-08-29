<?php

namespace Infinity\Dominion\Services;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Contracts\AuthorizationCache;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Contracts\AuthorizationResolver;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Domain\AuthorizationScope;

class DefaultAuthorizationResolver implements AuthorizationResolver
{
    /**
     * Create a new authorization resolver instance.
     */
    public function __construct(
        protected AuthorizationCatalog $catalog,
        protected AuthorizationCache $cache,
        protected ModelRegistry $models,
    ) {}

    /**
     * Resolve and cache the authorization decision for a permission.
     */
    public function decide(Model $model, mixed $permission, AuthorizationScope $scope): AuthorizationDecision
    {
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
        $connection = $model->getConnection();
        $permissionTable = $model->getTable();
        $identity = [
            'principal_type' => $principal->getMorphClass(),
            'principal_id' => $principal->getKey(),
        ];
        $scopeKeys = $this->scopeKeys($scope);
        $known = $connection->table($permissionTable)
            ->selectRaw('0 as precedence')
            ->where("{$permissionTable}.name", $permission);
        $denials = $connection->table('permission_denials')
            ->join($permissionTable, "{$permissionTable}.id", '=', 'permission_denials.permission_id')
            ->selectRaw('3 as precedence')
            ->where($identity)
            ->whereIn('permission_denials.scope_key', $scopeKeys)
            ->where("{$permissionTable}.name", $permission);
        $grants = $connection->table('permission_grants')
            ->join($permissionTable, "{$permissionTable}.id", '=', 'permission_grants.permission_id')
            ->selectRaw('2 as precedence')
            ->where($identity)
            ->whereIn('permission_grants.scope_key', $scopeKeys)
            ->where("{$permissionTable}.name", $permission);
        $roles = $connection->table('role_assignments')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_assignments.role_id')
            ->join($permissionTable, "{$permissionTable}.id", '=', 'permission_role.permission_id')
            ->selectRaw('1 as precedence')
            ->where($identity)
            ->whereIn('role_assignments.scope_key', $scopeKeys)
            ->where("{$permissionTable}.name", $permission);

        $precedence = $connection->query()
            ->fromSub($known->unionAll($denials)->unionAll($grants)->unionAll($roles), 'authorization_effects')
            ->max('precedence');

        if ($precedence === null) {
            return AuthorizationDecision::Abstain;
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
