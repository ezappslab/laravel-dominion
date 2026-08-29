<?php

namespace Infinity\Dominion\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
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
