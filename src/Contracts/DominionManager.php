<?php

namespace Infinity\Dominion\Contracts;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\PendingAuthorization;

/**
 * Creates immutable authorization operations for Eloquent principals.
 */
interface DominionManager
{
    /**
     * Begin an authorization operation for the given principal.
     */
    public function for(Model $principal): PendingAuthorization;
}
