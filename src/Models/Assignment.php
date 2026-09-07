<?php

namespace Infinity\Dominion\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Infinity\Dominion\Domain\AuthorizationScope;

/**
 * Persists one role grant or direct permission effect for a principal and scope.
 *
 * @property string $principal_type
 * @property string $principal_id
 * @property string|null $tenant_type
 * @property string|null $tenant_id
 * @property string $scope_key
 * @property int|null $role_id
 * @property int|null $permission_id
 * @property string $assignment_key
 * @property 'grant'|'allow'|'deny' $effect
 */
class Assignment extends Model
{
    public const EFFECT_ALLOW = 'allow';

    public const EFFECT_DENY = 'deny';

    public const EFFECT_GRANT = 'grant';

    /** @var list<string> */
    protected $fillable = [
        'principal_type',
        'principal_id',
        'tenant_type',
        'tenant_id',
        'scope_key',
        'role_id',
        'permission_id',
        'assignment_key',
        'effect',
    ];

    /**
     * Cast catalog foreign keys while preserving polymorphic string identifiers.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'permission_id' => 'integer',
        ];
    }

    /**
     * Resolve the configurable unified assignment table at runtime.
     */
    public function getTable(): string
    {
        return config('dominion.tables.assignments', 'dominion_assignments');
    }

    /**
     * Return the application model receiving this assignment.
     */
    public function principal(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Return the tenant for a tenant-scoped assignment, when one exists.
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Return the assigned role for role assignments.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(config('dominion.models.role', Role::class));
    }

    /**
     * Return the assigned permission for direct permission assignments.
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(config('dominion.models.permission', Permission::class));
    }

    /**
     * Restrict assignments to one principal.
     */
    public function scopeForPrincipal(Builder $query, Model $principal): Builder
    {
        return $query->where('principal_type', $principal->getMorphClass())
            ->where('principal_id', (string) $principal->getKey());
    }

    /**
     * Restrict assignments to the shared global scope.
     */
    public function scopeGlobally(Builder $query): Builder
    {
        return $query->where('scope_key', AuthorizationScope::global()->key());
    }

    /**
     * Restrict assignments to one tenant scope.
     */
    public function scopeForTenant(Builder $query, Model $tenant): Builder
    {
        return $query->where('scope_key', AuthorizationScope::tenant($tenant)->key());
    }

    /**
     * Restrict the query to role assignments.
     */
    public function scopeRoles(Builder $query): Builder
    {
        return $query->whereNotNull('role_id');
    }

    /**
     * Restrict the query to direct permission assignments.
     */
    public function scopePermissions(Builder $query): Builder
    {
        return $query->whereNotNull('permission_id');
    }

    /**
     * Restrict assignments to one stored effect.
     */
    public function scopeWithEffect(Builder $query, string $effect): Builder
    {
        return $query->where('effect', $effect);
    }
}
