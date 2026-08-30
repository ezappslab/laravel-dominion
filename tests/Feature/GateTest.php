<?php

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use Tests\Support\Post;
use Workbench\App\Models\User;

it('abstains for principals that are not enabled for Dominion', function (): void {
    $user = new class extends Authenticatable {};
    Permission::create(['name' => 'posts.update']);
    Gate::define('posts.update', fn (): bool => true);

    expect(Gate::forUser($user)->allows('posts.update'))->toBeTrue();
});

it('abstains from resource abilities for principals that are not enabled for Dominion', function (): void {
    $user = new class extends Authenticatable {};
    Gate::define('update', fn (): bool => true);

    expect(Gate::forUser($user)->allows('update', new Post))->toBeTrue();
});

it('denies unqualified resource abilities outside configured Dominion policies', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'resource@example.com',
        'password' => Hash::make('password'),
    ]);
    Gate::define('update', fn (): bool => true);

    expect($user->can('update', new Post))->toBeFalse();
});

it('resolves permission via Gate::before', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    Permission::create(['name' => 'posts.update']);

    expect($user->can('posts.update'))->toBeFalse();

    $user->allow('posts.update');

    expect($user->can('posts.update'))->toBeTrue();
});

it('resolves permission via role in Gate::before', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'posts.update']);

    $role->permissions()->attach($permission);

    $user->addRole($role);

    expect($user->can('posts.update'))->toBeTrue();
});

it('respects explicit deny in Gate::before', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'posts.update']);

    $role->permissions()->attach($permission);

    $user->addRole($role);
    $user->deny('posts.update');

    expect($user->can('posts.update'))
        ->toBeFalse();
});

it('is tenant-aware in Gate::before', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    Permission::create(['name' => 'posts.update']);

    // Allow for tenant 1
    $user->allow('posts.update', 1);

    // Current tenant context defaults to null in DefaultTenantContext
    expect($user->can('posts.update'))
        ->toBeFalse();

    // Mock tenant context to 1
    $this->mock(TenantContext::class)
        ->shouldReceive('currentScope')
        ->andReturn(AuthorizationScope::tenant(1));

    expect($user->can('posts.update'))->toBeTrue();

    // Deny for tenant 1
    $user->deny('posts.update', 1);

    expect($user->can('posts.update'))->toBeFalse();
});
