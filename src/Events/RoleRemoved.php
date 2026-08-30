<?php

namespace Infinity\Dominion\Events;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Domain\AuthorizationScope;

final readonly class RoleRemoved
{
    /**
     * Create a new role removed event.
     */
    public function __construct(
        public Model $principal,
        public string $role,
        public AuthorizationScope $scope,
    ) {}
}
