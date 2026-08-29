<?php

namespace Infinity\Dominion\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Infinity\Dominion\Contracts\AuthorizationResolver;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Services\AssignmentService;
use Infinity\Dominion\Services\ModelRegistry;
use InvalidArgumentException;

trait HasPermissions
{
    use ResolvesAuthorizationScope;

    /**
     * Get every directly granted permission across all scopes.
     */
    public function permissions(): MorphToMany
    {
        return $this->morphToMany(app(ModelRegistry::class)->permissionModel(), 'principal', 'permission_grants')
            ->withPivot(['tenant_type', 'tenant_id', 'scope_key'])
            ->withTimestamps();
    }

    /**
     * Get every directly denied permission across all scopes.
     */
    public function deniedPermissions(): MorphToMany
    {
        return $this->morphToMany(app(ModelRegistry::class)->permissionModel(), 'principal', 'permission_denials')
            ->withPivot(['tenant_type', 'tenant_id', 'scope_key'])
            ->withTimestamps();
    }

    /**
     * Grant a permission in the current, explicit tenant, or global scope.
     */
    public function grantPermission(mixed $permission, AuthorizationScope|Model|string|int|null $tenant = null): self
    {
        app(AssignmentService::class)->grant($this, $permission, $this->authorizationScope($tenant));

        return $this;
    }

    /**
     * Backward-compatible alias for granting a permission.
     */
    public function allow(mixed $permission, AuthorizationScope|Model|string|int|null $tenant = null): self
    {
        return $this->grantPermission($permission, $tenant);
    }

    /**
     * Deny a permission with precedence over grants and role permissions.
     */
    public function denyPermission(mixed $permission, AuthorizationScope|Model|string|int|null $tenant = null): self
    {
        app(AssignmentService::class)->deny($this, $permission, $this->authorizationScope($tenant));

        return $this;
    }

    /**
     * Backward-compatible alias for denying a permission.
     */
    public function deny(mixed $permission, AuthorizationScope|Model|string|int|null $tenant = null): self
    {
        return $this->denyPermission($permission, $tenant);
    }

    /**
     * Remove direct grants and denials for the selected scope.
     */
    public function revokePermission(mixed $permission, AuthorizationScope|Model|string|int|null $tenant = null): self
    {
        app(AssignmentService::class)->revoke($this, $permission, $this->authorizationScope($tenant));

        return $this;
    }

    /**
     * Resolve the complete tri-state decision for an ability.
     */
    public function authorizationDecision(mixed $permission, AuthorizationScope|Model|string|int|null $tenant = null): AuthorizationDecision
    {
        return app(AuthorizationResolver::class)->decide($this, $permission, $this->authorizationScope($tenant));
    }

    /**
     * Determine whether the final Dominion decision allows the permission.
     */
    public function hasPermission(mixed $permission, AuthorizationScope|Model|string|int|null $tenant = null): bool
    {
        return $this->authorizationDecision($permission, $tenant) === AuthorizationDecision::Allow;
    }

    /**
     * Apply a named role and permission profile atomically.
     */
    public function assignAuthorizationProfile(
        string $profile,
        AuthorizationScope|Model|string|int|null $tenant = null,
    ): self {
        $profiles = config('dominion.profiles', []);
        $definition = $profiles[$profile] ?? null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Dominion authorization profile [{$profile}] is not configured.");
        }

        app(AssignmentService::class)->applyProfile($this, $definition, $this->authorizationScope($tenant));

        return $this;
    }
}
