<?php

namespace Infinity\Dominion\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Contracts\AuthorizationCache;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use RuntimeException;
use Workbench\App\Models\User;

beforeEach(function (): void {
    config(['dominion.cache.enabled' => true]);
    config(['dominion.cache.store' => 'array']);
});

it('caches authorization results', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $permission = Permission::create(['name' => 'posts.edit']);
    $user->allow($permission);

    // Warm the decision cache before query logging starts.
    $user->hasPermission('posts.edit');

    DB::enableQueryLog();
    $user->hasPermission('posts.edit');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBeEmpty();
});

it('resolves a cold authorization decision with one database query', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);
    Permission::create(['name' => 'posts.edit']);

    DB::enableQueryLog();
    $decision = $user->hasPermission('posts.edit');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($decision)->toBeFalse()
        ->and($queries)->toHaveCount(1);
});

it('invalidates cache when role is added', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'posts.edit']);
    $role->permissions()->attach($permission);

    // First check (false, cached)
    expect($user->hasPermission('posts.edit'))->toBeFalse();

    // Add role (should invalidate cache)
    $user->addRole($role);

    // Second check (true)
    expect($user->hasPermission('posts.edit'))->toBeTrue();
});

it('invalidates cache when role is removed', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'posts.edit']);
    $role->permissions()->attach($permission);
    $user->addRole($role);

    // First check (true, cached)
    expect($user->hasPermission('posts.edit'))->toBeTrue();

    // Remove role (should invalidate cache)
    $user->removeRole($role);

    // Second check (false)
    expect($user->hasPermission('posts.edit'))->toBeFalse();
});

it('invalidates cache when permission is allowed', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $permission = Permission::create(['name' => 'posts.edit']);

    // First check (false, cached)
    expect($user->hasPermission('posts.edit'))->toBeFalse();

    // Allow (should invalidate cache)
    $user->allow($permission);

    // Second check (true)
    expect($user->hasPermission('posts.edit'))->toBeTrue();
});

it('invalidates cache when permission is denied', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $permission = Permission::create(['name' => 'posts.edit']);
    $user->allow($permission);

    // First check (true, cached)
    expect($user->hasPermission('posts.edit'))->toBeTrue();

    // Deny (should invalidate cache)
    $user->deny($permission);

    // Second check (false)
    expect($user->hasPermission('posts.edit'))->toBeFalse();
});

it('separates cache by tenant', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $permission = Permission::create(['name' => 'posts.edit']);

    // Global check (false)
    expect($user->hasPermission('posts.edit'))->toBeFalse();

    // Tenant 1 check (false)
    expect($user->hasPermission('posts.edit', 1))->toBeFalse();

    // Allow for Tenant 1 (should NOT invalidate global cache)
    $user->allow($permission, 1);

    // Tenant 1 check (true)
    expect($user->hasPermission('posts.edit', 1))->toBeTrue();

    // Global check (should still be false from cache)
    expect($user->hasPermission('posts.edit'))->toBeFalse();
});

it('does not invalidate cached decisions when an assignment transaction rolls back', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);
    $permission = Permission::create(['name' => 'posts.edit']);

    expect($user->hasPermission('posts.edit'))->toBeFalse();

    try {
        DB::transaction(function () use ($user, $permission): void {
            $user->allow($permission);

            throw new RuntimeException('Rollback assignment.');
        });
    } catch (RuntimeException) {
        // The rollback is expected by this regression test.
    }

    DB::enableQueryLog();
    $decision = $user->hasPermission('posts.edit');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($decision)->toBeFalse()
        ->and($queries)->toBeEmpty()
        ->and(DB::table('permission_grants')->count())->toBe(0);
});

it('rotates cache versions with a single atomic write', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);
    $cache = app(AuthorizationCache::class);
    $repository = Cache::store('array');
    $key = 'dominion:principal-version:'.hash('sha256', $user->getMorphClass().'|'.$user->getKey());

    $cache->invalidatePrincipal($user);
    $first = $repository->get($key);
    $cache->invalidatePrincipal($user);
    $second = $repository->get($key);

    expect($first)->toBeString()->not->toBe('initial')
        ->and($second)->toBeString()->not->toBe($first);
});
