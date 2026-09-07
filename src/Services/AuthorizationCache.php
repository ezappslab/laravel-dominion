<?php

namespace Infinity\Dominion\Services;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Domain\AuthorizationScope;

/**
 * Memoizes decisions and versions persistent keys without requiring cache tags.
 */
class AuthorizationCache
{
    /** @var array<string, AuthorizationDecision> Decisions cached for this service lifetime. */
    private array $memo = [];

    /**
     * Return a memoized or persistent decision, resolving it only when absent.
     *
     * @param  callable(): AuthorizationDecision  $resolver
     */
    public function remember(Model $principal, AuthorizationScope $scope, string $permission, callable $resolver): AuthorizationDecision
    {
        $key = $this->key($principal, $scope, $permission);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if (! config('dominion.cache.enabled', true)) {
            return $this->memo[$key] = $resolver();
        }

        $value = cache()->store(config('dominion.cache.store'))->remember($key, (int) config('dominion.cache.ttl', 300), fn (): string => $resolver()->value);

        return $this->memo[$key] = AuthorizationDecision::from($value);
    }

    /**
     * Rotate one principal's version and clear request-level decisions.
     */
    public function invalidate(Model $principal): void
    {
        $this->memo = [];

        if (config('dominion.cache.enabled', true)) {
            cache()->store(config('dominion.cache.store'))->put($this->versionKey($principal), bin2hex(random_bytes(12)), (int) config('dominion.cache.version_ttl', 3600));
        }
    }

    /**
     * Rotate the shared catalog version after synchronized data changes.
     */
    public function invalidateCatalog(): void
    {
        $this->memo = [];

        if (config('dominion.cache.enabled', true)) {
            cache()->store(config('dominion.cache.store'))->put($this->prefix().':catalog-version', bin2hex(random_bytes(12)), (int) config('dominion.cache.version_ttl', 3600));
        }
    }

    /**
     * Build a decision key containing current principal and catalog versions.
     */
    private function key(Model $principal, AuthorizationScope $scope, string $permission): string
    {
        $repo = cache()->store(config('dominion.cache.store'));
        $pv = config('dominion.cache.enabled', true) ? $repo->get($this->versionKey($principal), 'initial') : 'off';
        $cv = config('dominion.cache.enabled', true) ? $repo->get($this->prefix().':catalog-version', 'initial') : 'off';

        return $this->prefix().":decision:{$cv}:{$pv}:".hash('sha256', $principal->getMorphClass().'|'.$principal->getKey().'|'.$scope->key().'|'.$permission);
    }

    /**
     * Build the persistent version key for one principal identity.
     */
    private function versionKey(Model $principal): string
    {
        return $this->prefix().':principal-version:'.hash('sha256', $principal->getMorphClass().'|'.$principal->getKey());
    }

    /**
     * Return the configurable namespace used by every cache key.
     */
    private function prefix(): string
    {
        return (string) config('dominion.cache.prefix', 'dominion');
    }
}
