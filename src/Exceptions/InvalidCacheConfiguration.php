<?php

namespace Infinity\Dominion\Exceptions;

use InvalidArgumentException;

class InvalidCacheConfiguration extends InvalidArgumentException
{
    /**
     * Create an exception for an unsafe cache lifetime configuration.
     */
    public static function unsafeVersionTtl(int $decisionTtl, int $versionTtl): self
    {
        return new self("Dominion cache version TTL [{$versionTtl}] must be greater than the decision TTL [{$decisionTtl}].");
    }
}
