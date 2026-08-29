<?php

namespace Infinity\Dominion\Domain;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class AuthorizationScope
{
    /**
     * Create a new authorization scope instance.
     */
    private function __construct(
        public ?string $tenantType,
        public ?string $tenantId,
    ) {}

    /**
     * Create a scope that is shared across every tenant.
     */
    public static function global(): self
    {
        return new self(null, null);
    }

    /**
     * Create a tenant scope from a model, identifier, or morph type and ID.
     */
    public static function tenant(Model|string|int $tenant, string|int|null $id = null): self
    {
        if ($tenant instanceof Model) {
            $tenantId = $tenant->getKey();

            if ($tenantId === null) {
                throw new InvalidArgumentException('A tenant model must exist before it can be used as an authorization scope.');
            }

            return new self($tenant->getMorphClass(), (string) $tenantId);
        }

        if ($id !== null) {
            return new self((string) $tenant, (string) $id);
        }

        return new self((string) config('dominion.tenancy.tenant_type', 'tenant'), (string) $tenant);
    }

    /**
     * Determine whether this scope represents a global assignment.
     */
    public function isGlobal(): bool
    {
        return $this->tenantType === null;
    }

    /**
     * Return the stable database and cache key for this scope.
     */
    public function key(): string
    {
        if ($this->isGlobal()) {
            return 'global';
        }

        return hash('sha256', $this->tenantType.'|'.$this->tenantId);
    }
}
