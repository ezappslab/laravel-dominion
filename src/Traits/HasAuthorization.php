<?php

namespace Infinity\Dominion\Traits;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Models\Assignment;

/**
 * Composes Dominion's query concerns and manages a principal's lifecycle.
 */
trait HasAuthorization
{
    use HasPermissions;
    use HasRoles;

    /**
     * Identify trait users to the package's Gate callback.
     */
    public function dominionAuthorized(): bool
    {
        return true;
    }

    /**
     * Remove assignments after permanent deletion while preserving soft deletes.
     */
    protected static function bootHasAuthorization(): void
    {
        static::deleted(function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $assignment = config('dominion.models.assignment', Assignment::class);
            $assignment::query()->forPrincipal($model)->delete();
        });
    }
}
