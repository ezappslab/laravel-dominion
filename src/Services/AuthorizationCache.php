<?php

namespace Infinity\Dominion\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Infinity\Dominion\Contracts\AuthorizationCache as AuthorizationCacheContract;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Domain\AuthorizationScope;

class AuthorizationCache implements AuthorizationCacheContract
{
    /**
     * The configured cache repository.
     */
    protected Repository $cache;

    /**
     * Determine whether decision caching is enabled.
     */
    protected bool $enabled;

    /**
     * The decision cache lifetime in seconds.
     */
    protected int $ttl;

    /**
     * The prefix applied to Dominion cache keys.
     */
    protected string $prefix;

    /**
     * Create a new authorization cache instance.
     */
    public function __construct()
    {
        $this->enabled = (bool) config('dominion.cache.enabled', true);
        $this->ttl = (int) config('dominion.cache.ttl', 300);
        $this->prefix = (string) config('dominion.cache.prefix', 'dominion');
        $this->cache = Cache::store(config('dominion.cache.store'));
    }

    /**
     * Retrieve a cached decision for the current principal and catalog versions.
     */
    public function get(Model $principal, string $permission, AuthorizationScope $scope): ?AuthorizationDecision
    {
        if (! $this->enabled) {
            return null;
        }

        $value = $this->cache->get($this->decisionKey($principal, $permission, $scope));

        return is_string($value) ? AuthorizationDecision::tryFrom($value) : null;
    }

    /**
     * Store a decision without relying on cache-tag support.
     */
    public function put(Model $principal, string $permission, AuthorizationScope $scope, AuthorizationDecision $decision): void
    {
        if ($this->enabled) {
            $this->cache->put($this->decisionKey($principal, $permission, $scope), $decision->value, $this->ttl);
        }
    }

    /**
     * Advance the principal version so existing decision keys become stale.
     */
    public function invalidatePrincipal(Model $principal): void
    {
        $this->rotateVersion($this->principalVersionKey($principal));
    }

    /**
     * Advance the shared catalog version after enum or role-map changes.
     */
    public function invalidateCatalog(): void
    {
        $this->rotateVersion($this->catalogVersionKey());
    }

    /**
     * Build a versioned cache key for an authorization decision.
     */
    protected function decisionKey(Model $principal, string $permission, AuthorizationScope $scope): string
    {
        $identity = $principal->getMorphClass().'|'.$principal->getKey();
        $principalVersion = $this->version($this->principalVersionKey($principal));
        $catalogVersion = $this->version($this->catalogVersionKey());
        $digest = hash('sha256', $identity.'|'.$scope->key().'|'.$permission);

        return "{$this->prefix}:decision:{$catalogVersion}:{$principalVersion}:{$digest}";
    }

    /**
     * Build the cache version key for a principal.
     */
    protected function principalVersionKey(Model $principal): string
    {
        return "{$this->prefix}:principal-version:".hash('sha256', $principal->getMorphClass().'|'.$principal->getKey());
    }

    /**
     * Get the shared catalog version cache key.
     */
    protected function catalogVersionKey(): string
    {
        return "{$this->prefix}:catalog-version";
    }

    /**
     * Get the current integer version for a cache key.
     */
    protected function version(string $key): string
    {
        $version = $this->cache->get($key);

        return is_string($version) ? $version : 'initial';
    }

    /**
     * Atomically replace a cache version when caching is enabled.
     */
    protected function rotateVersion(string $key): void
    {
        if ($this->enabled) {
            $this->cache->forever($key, bin2hex(random_bytes(16)));
        }
    }
}
