<?php

namespace Tests\Support;

use Infinity\Dominion\Models\Permission;

class ConnectedPermission extends Permission
{
    /**
     * The connection used by the test catalog model.
     *
     * @var string
     */
    protected $connection = 'dominion';
}
