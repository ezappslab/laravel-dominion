<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Infinity\Dominion\Contracts\DominionPrincipal;
use Infinity\Dominion\Traits\HasDominionAuthorization;

class SoftDeletingPrincipal extends Model implements DominionPrincipal
{
    use HasDominionAuthorization;
    use SoftDeletes;

    protected $fillable = [
        'name',
    ];
}
