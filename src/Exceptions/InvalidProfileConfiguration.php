<?php

namespace Infinity\Dominion\Exceptions;

use InvalidArgumentException;

class InvalidProfileConfiguration extends InvalidArgumentException
{
    /**
     * Create an exception for an invalid assignment profile.
     */
    public static function for(string $profile, string $reason): self
    {
        return new self("Invalid Dominion profile [{$profile}]: {$reason}");
    }
}
