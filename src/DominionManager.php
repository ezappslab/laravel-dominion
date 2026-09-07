<?php

namespace Infinity\Dominion;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Services\AuthorizationCache;
use Infinity\Dominion\Services\Catalog;

/**
 * Default manager behind the facade and injectable manager contract.
 */
class DominionManager implements Contracts\DominionManager
{
    /**
     * Create a manager with shared stateless catalog and cache services.
     */
    public function __construct(private Catalog $catalog, private AuthorizationCache $cache) {}

    /**
     * Return a new operation so principal and scope state never leaks between calls.
     */
    public function for(Model $principal): PendingAuthorization
    {
        return new PendingAuthorization($principal, $this->catalog, $this->cache);
    }
}
