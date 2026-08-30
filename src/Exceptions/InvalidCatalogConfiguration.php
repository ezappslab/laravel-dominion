<?php

namespace Infinity\Dominion\Exceptions;

use InvalidArgumentException;

class InvalidCatalogConfiguration extends InvalidArgumentException
{
    /**
     * Create an exception for an invalid Dominion catalog option.
     */
    public static function for(string $key, string $reason): self
    {
        return new self("Invalid Dominion catalog configuration [{$key}]: {$reason}");
    }
}
