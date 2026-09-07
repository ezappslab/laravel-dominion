<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Contracts\DominionPrincipal;
use Infinity\Dominion\Traits\HasAuthorization;

class Principal extends Model implements DominionPrincipal
{
    use HasAuthorization;

    protected $guarded = [];

    public $timestamps = false;
}
