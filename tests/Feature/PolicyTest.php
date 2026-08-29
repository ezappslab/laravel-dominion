<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use Tests\Support\Post;
use Workbench\App\Models\User;

it('authorizes via policy correctly', function (): void {
    config(['dominion.policy.models' => [Post::class]]);

    Gate::policy(Post::class, config('dominion.policy.class'));

    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $post = new Post;

    Permission::create(['name' => 'posts.update']);

    expect($user->can('posts.update', $post))->toBeFalse();

    $user->allow('posts.update');

    expect($user->can('posts.update', $post))->toBeTrue();
});

it('maps standard policy abilities to table permissions', function (): void {
    Gate::policy(Post::class, config('dominion.policy.class'));

    $user = User::create([
        'name' => 'John Doe',
        'email' => 'policy@example.com',
        'password' => Hash::make('password'),
    ]);
    $post = new Post;

    Permission::create(['name' => 'posts.update']);
    $user->allow('posts.update');

    expect($user->can('update', $post))->toBeTrue();
});

it('authorizes via policy with roles', function (): void {
    config(['dominion.policy.models' => [Post::class]]);

    Gate::policy(Post::class, config('dominion.policy.class'));

    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $post = new Post;

    $role = Role::create(['name' => 'editor']);

    $permission = Permission::create(['name' => 'posts.delete']);

    $role->permissions()->attach($permission);

    expect($user->can('posts.delete', $post))->toBeFalse();

    $user->addRole($role);

    expect($user->can('posts.delete', $post))->toBeTrue();
});

it('is tenant aware via policy', function (): void {
    config(['dominion.policy.models' => [Post::class]]);

    Gate::policy(Post::class, config('dominion.policy.class'));

    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $post = new Post;
    Permission::create(['name' => 'posts.view']);

    $user->allow('posts.view', 1);

    expect($user->can('posts.view', $post))->toBeFalse();

    $this->mock(TenantContext::class)
        ->shouldReceive('currentScope')
        ->andReturn(AuthorizationScope::tenant(1));

    expect($user->can('posts.view', $post))->toBeTrue();

    $this->mock(TenantContext::class)
        ->shouldReceive('currentScope')
        ->andReturn(AuthorizationScope::tenant(2));

    expect($user->can('posts.view', $post))->toBeFalse();
});
