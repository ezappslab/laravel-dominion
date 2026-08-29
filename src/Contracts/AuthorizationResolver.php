<?php

namespace Infinity\Dominion\Contracts;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Domain\AuthorizationScope;

interface AuthorizationResolver
{
    /**
     * Resolve the principal's tri-state authorization decision.
     */
    public function decide(Model $model, mixed $permission, AuthorizationScope $scope): AuthorizationDecision;

    /**
     * Determine whether the principal is explicitly allowed.
     */
    public function hasPermission(Model $model, mixed $permission, AuthorizationScope $scope): bool;
}
