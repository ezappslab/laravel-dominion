<?php

namespace Infinity\Dominion\Traits;

trait HasDominionAuthorization
{
    /**
     * Compose Dominion's role and permission APIs on an Eloquent principal.
     */
    use HasPermissions;

    use HasRoles;
}
