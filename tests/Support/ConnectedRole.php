<?php

namespace Tests\Support;

use Infinity\Dominion\Models\Role;

class ConnectedRole extends Role
{
    /**
     * The connection used by the test catalog model.
     *
     * @var string
     */
    protected $connection = 'dominion';
}
