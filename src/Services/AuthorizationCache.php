<?php

namespace Infinity\Dominion\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Infinity\Dominion\Contracts\PermissionValueResolver;
use Infinity\Dominion\Models\Permission;

class AuthorizationCache
{
    protected Repository $cache;

    protected bool $enabled;

    protected int $ttl;

    protected string $prefix;

    public function __construct()
    {
        $this->enabled = config('dominion.cache.enabled', true);
        $this->ttl = config('dominion.cache.ttl', 300);
        $this->prefix = config('dominion.cache.prefix', 'dominion');
        $this->cache = Cache::store(config('dominion.cache.store'));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function get(Model $model, mixed $permission, mixed $tenantId): ?bool
    {
        if (! $this->enabled) {
            return null;
        }

        $key = $this->buildKey($model, $permission, $tenantId);

        if ($this->supportsTags()) {
            return $this->cache->tags($this->getTags($model, $tenantId))->get($key);
        }

        return $this->cache->get($key);
    }

    public function put(Model $model, mixed $permission, mixed $tenantId, bool $result): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = $this->buildKey($model, $permission, $tenantId);

        if ($this->supportsTags()) {
            $this->cache->tags($this->getTags($model, $tenantId))->put($key, $result, $this->ttl);
        } else {
            $this->cache->put($key, $result, $this->ttl);
        }
    }

    public function flushFor(Model $model, mixed $tenantId): void
    {
        if (! $this->enabled) {
            return;
        }

        if ($this->supportsTags()) {
            $this->cache->tags($this->getTags($model, $tenantId))->flush();
        } else {
            // Fallback: we can't easily clear by prefix without more complex logic or custom implementation
            // The requirements say: "clear all keys matching the principal prefix (acceptable tradeoff)"
            // But standard Laravel Cache repository doesn't support clearing by prefix.
            // Some stores might, but Repository interface doesn't.
            // If we don't have tags, and we want to "clear all keys matching the principal prefix",
            // we might have to just clear the whole cache if we don't have a better way,
            // OR we just accept that without tags, invalidation is harder.

            // Re-reading requirements: "clear all keys matching the principal prefix (acceptable tradeoff)"
            // In Laravel, without tags, there is no built-in way to clear by prefix across all drivers.
            // If the user uses 'file' or 'database' or 'redis' (without tags), they are out of luck for granular invalidation.

            // Actually, if they use 'redis', they could. But we should stick to Repository interface.
            // If they use a store that doesn't support tags, maybe we should just clear the whole store or do nothing?
            // "Correctness > performance"

            // If I can't clear by prefix, I should at least try to be correct.
            // Most people will use 'redis' or 'memcached' which support tags.
            // If they use 'array' (for tests), it DOES NOT support tags by default in Laravel unless it's the 'array' store?
            // Actually, 'array' and 'redis', 'memcached' support tags. 'file' and 'database' do not.

            // If no tags, maybe we just clear everything? That might be too aggressive.
            // But better than returning stale data.

            $this->cache->flush();
        }
    }

    protected function buildKey(Model $model, mixed $permission, mixed $tenantId): string
    {
        $principalType = $model->getMorphClass();
        $principalId = $model->getKey();
        $tenant = $tenantId ?? 'global';
        $permissionName = $this->normalizePermission($permission);

        return "{$this->prefix}:auth:{$principalType}:{$principalId}:{$tenant}:{$permissionName}";
    }

    protected function getTags(Model $model, mixed $tenantId): array
    {
        $principalType = $model->getMorphClass();
        $principalId = $model->getKey();
        $tenant = $tenantId ?? 'global';

        return [
            "{$this->prefix}:principal:{$principalType}:{$principalId}",
            "{$this->prefix}:tenant:{$tenant}",
            "{$this->prefix}:principal:{$principalType}:{$principalId}:{$tenant}",
        ];
    }

    protected function supportsTags(): bool
    {
        return method_exists($this->cache, 'tags');
    }

    protected function normalizePermission(mixed $permission): string
    {
        if ($permission instanceof Permission) {
            return $permission->name;
        }

        if (is_numeric($permission)) {
            // If it's an ID, we might need to resolve it to name for a stable key as per requirements
            // "permission must be normalized string"
            $p = Permission::find($permission);

            return $p ? $p->name : (string) $permission;
        }

        return app(PermissionValueResolver::class)->resolve($permission);
    }
}
