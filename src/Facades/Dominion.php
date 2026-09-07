<?php

namespace Infinity\Dominion\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Infinity\Dominion\Contracts\DominionManager;
use Infinity\Dominion\PendingAuthorization;

/**
 * Provides the primary entry point to Dominion's authorization API.
 *
 * @method static PendingAuthorization for(Model $principal)
 */
class Dominion extends Facade
{
    /**
     * Return the container binding proxied by this facade.
     */
    protected static function getFacadeAccessor(): string
    {
        return DominionManager::class;
    }
}
