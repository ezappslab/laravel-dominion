<?php

use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Models\Role;
use Tests\Support\CustomTenantContext;
use Tests\Support\TestRole;
use Workbench\App\Models\User;

beforeEach(function () {
    Role::create(['name' => 'ADMIN']);
    Role::create(['name' => 'EDITOR']);
});

it('can assign a role globally', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    // Default TenantContext returns null
    $user->addRole('ADMIN');

    expect($user->hasRole('ADMIN'))
        ->toBeTrue()
        ->and($user->roles()->wherePivot('tenant_id', null)->exists())
        ->toBeTrue();
});

it('can assign a role per tenant', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $user->addRole('EDITOR', 1);

    expect($user->hasRole('EDITOR', 1))
        ->toBeTrue()
        ->and($user->hasRole('EDITOR', 2))
        ->toBeFalse()
        ->and($user->hasRole('EDITOR'))
        ->toBeFalse();
});

it('can assign roles using enums', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $user->addRole(TestRole::ADMIN);

    expect($user->hasRole(TestRole::ADMIN))
        ->toBeTrue()
        ->and($user->hasRole('ADMIN'))
        ->toBeTrue();
});

it('can remove a role', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $user->addRole('ADMIN');
    expect($user->hasRole('ADMIN'))
        ->toBeTrue();

    $user->removeRole('ADMIN');
    expect($user->hasRole('ADMIN'))
        ->toBeFalse();
});

it('can remove a tenant-scoped role', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $user->addRole('EDITOR', 1);
    expect($user->hasRole('EDITOR', 1))
        ->toBeTrue();

    $user->removeRole('EDITOR', 1);
    expect($user->hasRole('EDITOR', 1))
        ->toBeFalse();
});

it('respects the current tenant context when adding roles', function () {
    config(['dominion.services.tenant_context' => CustomTenantContext::class]);

    // Re-bind to apply config change
    app()->singleton(TenantContext::class, function () {
        return new CustomTenantContext;
    });

    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $user->addRole('ADMIN'); // Should use tenant_id 123 from CustomTenantContext

    expect($user->hasRole('ADMIN', 123))
        ->toBeTrue()
        ->and($user->hasRole('ADMIN'))
        ->toBeTrue(); // hasRole also uses context if not provided
});
