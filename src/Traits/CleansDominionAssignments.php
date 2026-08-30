<?php

namespace Infinity\Dominion\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Infinity\Dominion\Services\AssignmentService;

trait CleansDominionAssignments
{
    /**
     * Remove authorization data when a principal is permanently deleted.
     */
    public static function bootCleansDominionAssignments(): void
    {
        $purge = fn (Model $principal) => app(AssignmentService::class)->purgePrincipal($principal);

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::registerModelEvent('forceDeleted', $purge);

            return;
        }

        static::deleted($purge);
    }
}
