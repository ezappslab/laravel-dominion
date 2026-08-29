<?php

namespace Infinity\Dominion\Events;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Domain\AuthorizationScope;

final readonly class PermissionRevoked
{
    /**
     * Create a new permission revoked event.
     */
    public function __construct(
        public Model $principal,
        public string $permission,
        public AuthorizationScope $scope,
    ) {}
}
