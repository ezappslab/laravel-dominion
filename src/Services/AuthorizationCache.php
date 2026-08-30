<?php

namespace Infinity\Dominion\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Infinity\Dominion\Contracts\AuthorizationCache as AuthorizationCacheContract;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Exceptions\InvalidCacheConfiguration;
use Infinity\Dominion\Exceptions\InvalidPrincipal;

class AuthorizationCache implements AuthorizationCacheContract
{
    /**
     * The configured cache repository.
     */
    protected ?Repository $cache = null;

    /**
     * Determine whether decision caching is enabled.
     */
    protected bool $enabled;

    /**
     * The decision cache lifetime in seconds.
     */
    protected int $ttl = 0;

    /**
     * The cache lifetime for principal and catalog version tokens.
     */
    protected int $versionTtl = 0;

    /**
     * The prefix applied to Dominion cache keys.
     */
    protected string $prefix = 'dominion';

    /**
     * Create a new authorization cache instance.
     */
    public function __construct()
    {
        $enabled = config('dominion.cache.enabled', true);

        if (! is_bool($enabled)) {
            throw InvalidCacheConfiguration::for('enabled', 'the value must be a boolean.');
        }

        $this->enabled = $enabled;

        if (! $this->enabled) {
            return;
        }

        $ttl = config('dominion.cache.ttl', 300);
        $versionTtl = config('dominion.cache.version_ttl', 3600);
        $prefix = config('dominion.cache.prefix', 'dominion');
        $store = config('dominion.cache.store');

        if (! is_int($ttl) || $ttl <= 0) {
            throw InvalidCacheConfiguration::for('ttl', 'the value must be a positive integer.');
        }

        if (! is_int($versionTtl) || $versionTtl <= 0) {
            throw InvalidCacheConfiguration::for('version_ttl', 'the value must be a positive integer.');
        }

        if (! is_string($prefix) || trim($prefix) === '') {
            throw InvalidCacheConfiguration::for('prefix', 'the value must be a non-empty string.');
        }

        if ($store !== null && (! is_string($store) || trim($store) === '')) {
            throw InvalidCacheConfiguration::for('store', 'the value must be null or a non-empty string.');
        }

        if (is_string($store) && ! is_array(config("cache.stores.{$store}"))) {
            throw InvalidCacheConfiguration::for('store', "cache store [{$store}] is not configured.");
        }

        $this->ttl = $ttl;
        $this->versionTtl = $versionTtl;
        $this->prefix = $prefix;

        if ($this->versionTtl <= $this->ttl) {
            throw InvalidCacheConfiguration::unsafeVersionTtl($this->ttl, $this->versionTtl);
        }

        $this->cache = Cache::store($store);
    }

    /**
     * Retrieve a cached decision for the current principal and catalog versions.
     */
    public function get(Model $principal, string $permission, AuthorizationScope $scope): ?AuthorizationDecision
    {
        if (! $this->enabled) {
            return null;
        }

        $value = $this->repository()->get($this->decisionKey($principal, $permission, $scope));

        return is_string($value) ? AuthorizationDecision::tryFrom($value) : null;
    }

    /**
     * Store a decision without relying on cache-tag support.
     */
    public function put(Model $principal, string $permission, AuthorizationScope $scope, AuthorizationDecision $decision): void
    {
        if ($this->enabled) {
            $this->repository()->put($this->decisionKey($principal, $permission, $scope), $decision->value, $this->ttl);
        }
    }

    /**
     * Advance the principal version so existing decision keys become stale.
     */
    public function invalidatePrincipal(Model $principal): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->rotateVersion($this->principalVersionKey($this->principalIdentity($principal)));
    }

    /**
     * Advance the shared catalog version after enum or role-map changes.
     */
    public function invalidateCatalog(): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->rotateVersion($this->catalogVersionKey());
    }

    /**
     * Build a versioned cache key for an authorization decision.
     */
    protected function decisionKey(Model $principal, string $permission, AuthorizationScope $scope): string
    {
        $identity = $this->principalIdentity($principal);
        $principalVersion = $this->version($this->principalVersionKey($identity));
        $catalogVersion = $this->version($this->catalogVersionKey());
        $digest = hash('sha256', $identity.'|'.$scope->key().'|'.$permission);

        return "{$this->prefix}:decision:{$catalogVersion}:{$principalVersion}:{$digest}";
    }

    /**
     * Build the cache version key for a principal identity.
     */
    protected function principalVersionKey(string $identity): string
    {
        return "{$this->prefix}:principal-version:".hash('sha256', $identity);
    }

    /**
     * Get a stable identity for a persisted principal.
     */
    protected function principalIdentity(Model $principal): string
    {
        $key = $principal->getKey();

        if (! $principal->exists || $key === null) {
            throw InvalidPrincipal::notPersisted($principal);
        }

        return $principal->getMorphClass().'|'.$key;
    }

    /**
     * Get the shared catalog version cache key.
     */
    protected function catalogVersionKey(): string
    {
        return "{$this->prefix}:catalog-version";
    }

    /**
     * Get the current version token for a cache key.
     */
    protected function version(string $key): string
    {
        $version = $this->repository()->get($key);

        return is_string($version) ? $version : 'initial';
    }

    /**
     * Atomically replace a cache version when caching is enabled.
     */
    protected function rotateVersion(string $key): void
    {
        if ($this->enabled) {
            $this->repository()->put($key, bin2hex(random_bytes(16)), $this->versionTtl);
        }
    }

    /**
     * Return the cache repository initialized for enabled caching.
     */
    protected function repository(): Repository
    {
        if ($this->cache === null) {
            throw new \LogicException('The Dominion cache repository is unavailable while caching is disabled.');
        }

        return $this->cache;
    }
}
