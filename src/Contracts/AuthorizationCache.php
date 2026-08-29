<?php

namespace Infinity\Dominion\Contracts;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Domain\AuthorizationScope;

interface AuthorizationCache
{
    /**
     * Retrieve a previously computed decision, when available.
     */
    public function get(Model $principal, string $permission, AuthorizationScope $scope): ?AuthorizationDecision;

    /**
     * Cache a computed authorization decision.
     */
    public function put(Model $principal, string $permission, AuthorizationScope $scope, AuthorizationDecision $decision): void;

    /**
     * Invalidate every cached decision for the given principal.
     */
    public function invalidatePrincipal(Model $principal): void;

    /**
     * Invalidate decisions affected by catalog or role-map changes.
     */
    public function invalidateCatalog(): void;
}
