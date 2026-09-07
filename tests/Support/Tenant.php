<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
