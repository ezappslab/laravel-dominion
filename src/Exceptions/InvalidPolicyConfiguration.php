<?php

namespace Infinity\Dominion\Exceptions;

use InvalidArgumentException;

class InvalidPolicyConfiguration extends InvalidArgumentException
{
    /**
     * Create an exception for an invalid policy option.
     */
    public static function for(string $key, string $reason): self
    {
        return new self("Invalid Dominion policy configuration [{$key}]: {$reason}");
    }
}
