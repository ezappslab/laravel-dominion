<?php

namespace Infinity\Dominion\Exceptions;

use InvalidArgumentException;

class InvalidCacheConfiguration extends InvalidArgumentException
{
    public static function for(string $key, string $reason): self
    {
        return new self("Invalid Dominion cache configuration [{$key}]: {$reason}");
    }

    /**
     * Create an exception for an unsafe cache lifetime configuration.
     */
    public static function unsafeVersionTtl(int $decisionTtl, int $versionTtl): self
    {
        return new self("Dominion cache version TTL [{$versionTtl}] must be greater than the decision TTL [{$decisionTtl}].");
    }
}
