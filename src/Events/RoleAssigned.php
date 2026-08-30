<?php

namespace Infinity\Dominion\Events;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Domain\AuthorizationScope;

final readonly class RoleAssigned
{
    /**
     * Create a new role assigned event.
     */
    public function __construct(
        public Model $principal,
        public string $role,
        public AuthorizationScope $scope,
    ) {}
}
