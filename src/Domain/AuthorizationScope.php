<?php

namespace Infinity\Dominion\Domain;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Identifies either the shared global scope or one persisted tenant scope.
 */
final readonly class AuthorizationScope
{
    /**
     * Restrict construction to validated named constructors.
     */
    private function __construct(public ?string $tenantType, public ?string $tenantId) {}

    /**
     * Create the scope inherited by every tenant.
     */
    public static function global(): self
    {
        return new self(null, null);
    }

    /**
     * Create a scope for a persisted instance of the configured tenant model.
     */
    public static function tenant(Model $tenant): self
    {
        if (! $tenant->exists || $tenant->getKey() === null) {
            throw new InvalidArgumentException('The tenant must be persisted.');
        }

        $configured = config('dominion.tenant.model');

        if (is_string($configured) && ! $tenant instanceof $configured) {
            throw new InvalidArgumentException("Tenant must be an instance of [{$configured}].");
        }

        return new self($tenant->getMorphClass(), (string) $tenant->getKey());
    }

    /**
     * Determine whether this scope has no tenant boundary.
     */
    public function isGlobal(): bool
    {
        return $this->tenantType === null;
    }

    /**
     * Return the stable value stored in assignments and cache keys.
     */
    public function key(): string
    {
        return $this->isGlobal() ? 'global' : hash('sha256', $this->tenantType.'|'.$this->tenantId);
    }
}
