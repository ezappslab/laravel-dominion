<?php

namespace Infinity\Dominion\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
        $permissionId = $permissionModel::query()->where('name', $permission)->value('id');

        if ($permissionId === null) {
            return AuthorizationDecision::Abstain;
        }

        $identity = [
            'principal_type' => $principal->getMorphClass(),
            'principal_id' => $principal->getKey(),
        ];
        $scopeKeys = $this->scopeKeys($scope);

        if ($this->assignmentExists('permission_denials', $identity, $scopeKeys, 'permission_id', $permissionId)) {
            return AuthorizationDecision::Deny;
        }

        if ($this->assignmentExists('permission_grants', $identity, $scopeKeys, 'permission_id', $permissionId)) {
            return AuthorizationDecision::Allow;
        }

        $rolePermission = DB::table('role_assignments')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_assignments.role_id')
            ->where($identity)
            ->whereIn('role_assignments.scope_key', $scopeKeys)
            ->where('permission_role.permission_id', $permissionId)
            ->exists();

        return $rolePermission ? AuthorizationDecision::Allow : AuthorizationDecision::Deny;
    }

    /**
     * Determine whether a matching scoped assignment exists.
     *
     * @param  array{principal_type: string, principal_id: mixed}  $identity
     * @param  list<string>  $scopeKeys
     */
    protected function assignmentExists(
        string $table,
        array $identity,
        array $scopeKeys,
        string $foreignKey,
        mixed $foreignId,
    ): bool {
        return DB::table($table)
            ->where($identity)
            ->whereIn('scope_key', $scopeKeys)
            ->where($foreignKey, $foreignId)
            ->exists();
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
