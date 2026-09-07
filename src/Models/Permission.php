<?php

namespace Infinity\Dominion\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persists a synchronized permission value and its optional default effect.
 *
 * @property string $name
 * @property 'allow'|'deny'|null $default_effect
 */
class Permission extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'default_effect'];

    /**
     * Resolve the configurable permission table at runtime.
     */
    public function getTable(): string
    {
        return config('dominion.tables.permissions', 'dominion_permissions');
    }
}
