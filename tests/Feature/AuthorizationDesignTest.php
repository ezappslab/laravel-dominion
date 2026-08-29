<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Models\Role;
use Tests\Support\TestPermission;
use Tests\Support\TestRole;
use Workbench\App\Models\User;

beforeEach(function (): void {
    config([
        'dominion.catalog.role_enum' => TestRole::class,
        'dominion.catalog.permission_enums' => [TestPermission::class],
        'dominion.catalog.role_permissions' => [
            TestRole::ADMIN->name => ['*'],
            TestRole::EDITOR->name => [TestPermission::UPDATE],
        ],
        'dominion.profiles.member' => [
            'roles' => [TestRole::EDITOR],
            'permissions' => [TestPermission::CREATE],
        ],
    ]);

    $this->artisan('dominion:sync')->assertSuccessful();
    $this->user = User::create([
        'name' => 'Dominion User',
        'email' => fake()->unique()->safeEmail(),
        'password' => Hash::make('password'),
    ]);
});

it('inherits global roles into tenant scopes', function (): void {
    $this->user->assignRole(TestRole::EDITOR, AuthorizationScope::global());

    expect($this->user->hasPermission(TestPermission::UPDATE, 42))->toBeTrue()
        ->and($this->user->hasRole(TestRole::EDITOR, 42))->toBeTrue();
});

it('uses explicit denials before global grants', function (): void {
    $this->user->grantPermission(TestPermission::CREATE, AuthorizationScope::global());
    $this->user->denyPermission(TestPermission::CREATE, 42);

    expect($this->user->hasPermission(TestPermission::CREATE, 42))->toBeFalse()
        ->and($this->user->hasPermission(TestPermission::CREATE, AuthorizationScope::global()))->toBeTrue();
});

it('keeps global scope explicit while a tenant context is active', function (): void {
    $this->mock(TenantContext::class)
        ->shouldReceive('currentScope')
        ->andReturn(AuthorizationScope::tenant(42));

    $this->user->grantPermission(TestPermission::CREATE, AuthorizationScope::global());

    expect($this->user->hasPermission(TestPermission::CREATE))->toBeTrue()
        ->and($this->user->authorizationDecision('unknown.permission'))
        ->toBe(AuthorizationDecision::Abstain);
});

it('applies profiles and keeps assignments idempotent', function (): void {
    $this->user->assignAuthorizationProfile('member', tenant: 42);
    $this->user->assignAuthorizationProfile('member', tenant: 42);

    expect($this->user->hasRole(TestRole::EDITOR, 42))->toBeTrue()
        ->and($this->user->hasPermission(TestPermission::CREATE, 42))->toBeTrue()
        ->and($this->user->roles()->wherePivot('scope_key', AuthorizationScope::tenant(42)->key())->count())->toBe(1);
});

it('lets Laravel authorize abilities outside the Dominion catalog', function (): void {
    Gate::define('external.ability', fn (User $user): bool => true);

    expect($this->user->can('external.ability'))->toBeTrue();
});

it('synchronizes wildcard role maps', function (): void {
    $admin = Role::where('name', TestRole::ADMIN->name)->firstOrFail();

    expect($admin->permissions)->toHaveCount(2);
});
