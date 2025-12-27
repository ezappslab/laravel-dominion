<?php

namespace Infinity\Dominion\Contracts;

interface PermissionValueResolver
{
    /**
     * Resolve the permission value to a string.
     */
    public function resolve(mixed $permission): string;
}
