<?php

namespace Infinity\Dominion\Exceptions;

use InvalidArgumentException;

class InvalidTableConfiguration extends InvalidArgumentException
{
    public static function for(string $reason): self
    {
        return new self("Invalid Dominion table configuration: {$reason}");
    }
}
