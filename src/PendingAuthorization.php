<?php

namespace Infinity\Dominion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Models\Assignment;
use Infinity\Dominion\Services\AuthorizationCache;
use Infinity\Dominion\Services\Catalog;
use InvalidArgumentException;

/**
 * Executes role and permission operations for one principal in an explicit scope.
 */
final readonly class PendingAuthorization
{
    /**
     * Create an immutable, principal-bound authorization operation.
     */
    public function __construct(private Model $principal, private Catalog $catalog, private AuthorizationCache $cache, private ?AuthorizationScope $scope = null) {}

    /**
     * Return a new operation targeting assignments shared across all tenants.
     */
    public function globally(): self
    {
        return new self($this->principal, $this->catalog, $this->cache, AuthorizationScope::global());
    }

    /**
     * Return a new operation targeting the supplied persisted tenant.
     */
    public function in(Model $tenant): self
    {
        return new self($this->principal, $this->catalog, $this->cache, AuthorizationScope::tenant($tenant));
    }

    /**
     * Grant a synchronized role in the selected scope.
     */
    public function grant(mixed $role): self
    {
        return $this->writeRole($role, true);
    }

    /**
     * Revoke a synchronized role from the selected scope.
     */
    public function revoke(mixed $role): self
    {
        return $this->writeRole($role, false);
    }

    /**
     * Store a direct permission allowance, replacing a denial at this scope.
     */
    public function allow(mixed $permission): self
    {
        return $this->writePermission($permission, 'allow');
    }

    /**
     * Store a direct permission denial, replacing an allowance at this scope.
     */
    public function deny(mixed $permission): self
    {
        return $this->writePermission($permission, 'deny');
    }

    /**
     * Remove the direct effect so roles or the catalog default can decide.
     */
    public function forget(mixed $permission): self
    {
        return $this->writePermission($permission, null);
    }

    /**
     * Determine whether the role exists in the selected or inherited global scope.
     */
    public function hasRole(mixed $role): bool
    {
        $roleId = $this->roleId($this->catalog->role($role));
        if ($roleId === null) {
            return false;
        }
        foreach ($this->scopes() as $scope) {
            if ($this->assignments()->where('scope_key', $scope->key())->where('role_id', $roleId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the final cached decision for a permission.
     */
    public function decision(mixed $permission): AuthorizationDecision
    {
        $name = $this->catalog->permission($permission);

        return $this->cache->remember($this->principal, $this->resolvedScope(), $name, fn () => $this->resolve($name));
    }

    /**
     * Determine whether the resolved permission decision allows access.
     */
    public function isAllowed(mixed $permission): bool
    {
        return $this->decision($permission) === AuthorizationDecision::Allow;
    }

    /**
     * Determine whether the resolved permission decision denies access.
     */
    public function isDenied(mixed $permission): bool
    {
        return ! $this->isAllowed($permission);
    }

    /**
     * Resolve several permissions using normalized values as result keys.
     *
     * @return array<string, bool>
     */
    public function checkMany(iterable $permissions): array
    {
        $result = [];
        foreach ($permissions as $permission) {
            $name = $this->catalog->permission($permission);
            $result[$name] = $this->isAllowed($permission);
        }

        return $result;
    }

    /**
     * Materialize a configured profile as assignments in one transaction.
     */
    public function applyProfile(string $name): self
    {
        $profile = config("dominion.profiles.{$name}");
        if (! is_array($profile)) {
            throw new InvalidArgumentException("Dominion profile [{$name}] is not configured.");
        }
        DB::transaction(function () use ($profile): void {
            foreach ($profile['roles'] ?? [] as $role) {
                $this->writeRole($role, true, false);
            }
            foreach ($profile['allow'] ?? [] as $permission) {
                $this->writePermission($permission, 'allow', false);
            }
            foreach ($profile['deny'] ?? [] as $permission) {
                $this->writePermission($permission, 'deny', false);
            }
        });
        $this->cache->invalidate($this->principal);

        return $this;
    }

    /**
     * Apply direct, role, default, and fallback precedence to one permission.
     */
    private function resolve(string $name): AuthorizationDecision
    {
        $permission = $this->permissionModel()::query()->where('name', $name)->first();
        if ($permission === null) {
            return AuthorizationDecision::Deny;
        }
        $scopes = $this->scopes();

        // Resolve direct effects before roles, preserving tenant-before-global specificity.
        foreach ($scopes as $scope) {
            $effect = $this->assignments()->where('scope_key', $scope->key())->where('permission_id', $permission->getKey())->value('effect');
            if ($effect !== null) {
                return AuthorizationDecision::from($effect);
            }
        }
        foreach ($scopes as $scope) {
            $allowed = $this->assignments()->where('scope_key', $scope->key())->whereNotNull('role_id')
                ->whereExists(function ($query) use ($permission): void {
                    $pivot = config('dominion.tables.role_permissions', 'dominion_role_permissions');
                    $assignments = config('dominion.tables.assignments', 'dominion_assignments');
                    $query->selectRaw('1')->from($pivot)->whereColumn("{$pivot}.role_id", "{$assignments}.role_id")->where("{$pivot}.permission_id", $permission->getKey());
                })->exists();
            if ($allowed) {
                return AuthorizationDecision::Allow;
            }
        }

        return $permission->default_effect === 'allow' ? AuthorizationDecision::Allow : AuthorizationDecision::Deny;
    }

    /**
     * Insert or remove an idempotent role assignment and invalidate decisions.
     */
    private function writeRole(mixed $role, bool $grant, bool $invalidate = true): self
    {
        $id = $this->roleId($this->catalog->role($role));
        if ($id === null) {
            throw new InvalidArgumentException('Role is not synchronized. Run dominion:sync.');
        }
        $identity = $this->identity() + ['assignment_key' => "role:{$id}"];
        if ($grant) {
            $this->assignmentModel()::query()->updateOrCreate($identity, ['role_id' => $id, 'permission_id' => null, 'effect' => Assignment::EFFECT_GRANT]);
        } else {
            $this->assignmentModel()::query()->where($identity)->delete();
        }
        if ($invalidate) {
            $this->cache->invalidate($this->principal);
        }

        return $this;
    }

    /**
     * Upsert or forget one mutually exclusive direct permission effect.
     */
    private function writePermission(mixed $permission, ?string $effect, bool $invalidate = true): self
    {
        $id = $this->permissionModel()::query()->where('name', $this->catalog->permission($permission))->value('id');
        if ($id === null) {
            throw new InvalidArgumentException('Permission is not synchronized. Run dominion:sync.');
        }
        $identity = $this->identity() + ['assignment_key' => "permission:{$id}"];
        if ($effect === null) {
            $this->assignmentModel()::query()->where($identity)->delete();
        } else {
            $this->assignmentModel()::query()->updateOrCreate($identity, ['role_id' => null, 'permission_id' => $id, 'effect' => $effect]);
        }
        if ($invalidate) {
            $this->cache->invalidate($this->principal);
        }

        return $this;
    }

    /**
     * Return the selected scope or fail before an ambiguous operation can run.
     */
    private function resolvedScope(): AuthorizationScope
    {
        return $this->scope ?? throw new InvalidArgumentException('Choose a scope with globally() or in($tenant).');
    }

    /**
     * Return scopes from most specific to least specific.
     *
     * @return list<AuthorizationScope>
     */
    private function scopes(): array
    {
        $scope = $this->resolvedScope();

        return $scope->isGlobal() ? [$scope] : [$scope, AuthorizationScope::global()];
    }

    /**
     * Build the stable principal, tenant, and scope columns for a mutation.
     *
     * @return array<string, string|null>
     */
    private function identity(): array
    {
        if (! $this->principal->exists || $this->principal->getKey() === null) {
            throw new InvalidArgumentException('The principal must be persisted.');
        }
        $scope = $this->resolvedScope();

        return ['principal_type' => $this->principal->getMorphClass(), 'principal_id' => (string) $this->principal->getKey(), 'tenant_type' => $scope->tenantType, 'tenant_id' => $scope->tenantId, 'scope_key' => $scope->key()];
    }

    /**
     * Start an assignment query constrained to this principal.
     */
    private function assignments()
    {
        return $this->assignmentModel()::query()->forPrincipal($this->principal);
    }

    /**
     * Resolve a synchronized role's database key by normalized name.
     */
    private function roleId(string $name): mixed
    {
        $class = config('dominion.models.role', Models\Role::class);

        return $class::query()->where('name', $name)->value('id');
    }

    /**
     * Return the configured permission model class.
     *
     * @return class-string<Models\Permission>
     */
    private function permissionModel(): string
    {
        return config('dominion.models.permission', Models\Permission::class);
    }

    /**
     * Return the configured assignment model class.
     *
     * @return class-string<Assignment>
     */
    private function assignmentModel(): string
    {
        return config('dominion.models.assignment', Assignment::class);
    }
}
