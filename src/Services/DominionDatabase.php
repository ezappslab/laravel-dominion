<?php

namespace Infinity\Dominion\Services;

use Illuminate\Database\Connection;
use InvalidArgumentException;

class DominionDatabase
{
    /**
     * Create a new Dominion database connection resolver.
     */
    public function __construct(
        protected ModelRegistry $models,
    ) {}

    /**
     * Resolve the shared connection used by every Dominion table.
     */
    public function connection(): Connection
    {
        $roleModel = $this->models->roleModel();
        $permissionModel = $this->models->permissionModel();
        $roleConnection = (new $roleModel)->getConnection();
        $permissionConnection = (new $permissionModel)->getConnection();

        if ($roleConnection->getName() !== $permissionConnection->getName()) {
            throw new InvalidArgumentException('The configured Dominion role and permission models must use the same database connection.');
        }

        return $permissionConnection;
    }
}
