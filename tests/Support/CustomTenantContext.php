<?php

namespace Tests\Support;

use Infinity\Dominion\Contracts\TenantContext;

class CustomTenantContext implements TenantContext
{
    public function getTenantId(): mixed
    {
        return 123;
    }
}
