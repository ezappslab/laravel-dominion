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
    protected Repository $cache;

    protected bool $enabled;

    protected int $ttl;

    protected string $prefix;

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
        $this->incrementVersion($this->principalVersionKey($principal));
    }

    /**
     * Advance the shared catalog version after enum or role-map changes.
     */
    public function invalidateCatalog(): void
    {
        $this->incrementVersion($this->catalogVersionKey());
    }

    protected function decisionKey(Model $principal, string $permission, AuthorizationScope $scope): string
    {
        $identity = $principal->getMorphClass().'|'.$principal->getKey();
        $principalVersion = $this->version($this->principalVersionKey($principal));
        $catalogVersion = $this->version($this->catalogVersionKey());
        $digest = hash('sha256', $identity.'|'.$scope->key().'|'.$permission);

        return "{$this->prefix}:decision:{$catalogVersion}:{$principalVersion}:{$digest}";
    }

    protected function principalVersionKey(Model $principal): string
    {
        return "{$this->prefix}:principal-version:".hash('sha256', $principal->getMorphClass().'|'.$principal->getKey());
    }

    protected function catalogVersionKey(): string
    {
        return "{$this->prefix}:catalog-version";
    }

    protected function version(string $key): int
    {
        return (int) $this->cache->get($key, 1);
    }

    protected function incrementVersion(string $key): void
    {
        if ($this->enabled) {
            $this->cache->forever($key, $this->version($key) + 1);
        }
    }
}
