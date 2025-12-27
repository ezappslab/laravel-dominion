<?php

namespace Infinity\Dominion\Contracts;

interface RoleValueResolver
{
    /**
     * Resolve the role value to a string.
     */
    public function resolve(mixed $role): string;
}
