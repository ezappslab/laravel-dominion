<?php

namespace Infinity\Dominion\Exceptions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class InvalidPrincipal extends InvalidArgumentException
{
    /**
     * Create an exception for a principal that has not been persisted.
     */
    public static function notPersisted(Model $principal): self
    {
        return new self('The Dominion principal ['.$principal::class.'] must be persisted before authorization can be checked.');
    }

    /**
     * Create an exception for a principal without an assignment identity.
     */
    public static function missingKey(Model $principal): self
    {
        return new self('The Dominion principal ['.$principal::class.'] must have a primary key before its assignments can be purged.');
    }
}
