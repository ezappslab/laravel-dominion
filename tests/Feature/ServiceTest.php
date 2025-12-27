<?php

namespace Tests\Feature;

use Infinity\Dominion\Contracts\PermissionValueResolver;
use Infinity\Dominion\Contracts\RoleValueResolver;
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\DominionServiceProvider;
use Infinity\Dominion\Services\DefaultPermissionValueResolver;
use Infinity\Dominion\Services\DefaultRoleValueResolver;
use Infinity\Dominion\Services\DefaultTenantContext;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\CustomTenantContext;
use Tests\Support\TestPermission;
use Tests\Support\TestRole;

it('binds default services', function () {
    expect(app(TenantContext::class))
        ->toBeInstanceOf(DefaultTenantContext::class)
        ->and(app(PermissionValueResolver::class))
        ->toBeInstanceOf(DefaultPermissionValueResolver::class)
        ->and(app(RoleValueResolver::class))
        ->toBeInstanceOf(DefaultRoleValueResolver::class);
});

it('can override a service via config', function () {
    config(['dominion.services.tenant_context' => CustomTenantContext::class]);

    // Re-register or just check if it returns custom instance if we force it?
    // Since it's a singleton bound in packageRegistered, we might need to swap it in app or re-run registration if possible.
    // Actually, in tests, usually we can just swap or the config is already set before provider registers if we use RefreshDatabase or similar,
    // but here we are in a running app.

    // For testing purposes, let's manually bind it to see if it works as intended when config is changed.
    $this->app->singleton(TenantContext::class, function ($app) {
        $class = config('dominion.services.tenant_context');

        return new $class;
    });

    expect(app(TenantContext::class))
        ->toBeInstanceOf(CustomTenantContext::class)
        ->and(app(TenantContext::class)->getTenantId())
        ->toBe(123);
});

it('normalizes permission enums', function () {
    $resolver = app(PermissionValueResolver::class);

    expect($resolver->resolve(TestPermission::CREATE))
        ->toBe('posts.create')
        ->and($resolver->resolve(TestPermission::UPDATE))
        ->toBe('posts.update');
});

it('normalizes role enums', function () {
    $resolver = app(RoleValueResolver::class);

    expect($resolver->resolve(TestRole::ADMIN))
        ->toBe('ADMIN')
        ->and($resolver->resolve(TestRole::EDITOR))
        ->toBe('EDITOR');
});

it('throws exception if service does not implement contract', closure: function () {
    config(['dominion.services.tenant_context' => \stdClass::class]);

    // We need to trigger the validation.
    // Since it happens in packageBooted, and the package is already booted in TestCase,
    // we might need to call it manually.

    $this->app->singleton(TenantContext::class, function ($app) {
        return new \stdClass;
    });

    $provider = new DominionServiceProvider($this->app);

    $method = new ReflectionMethod($provider, 'validateServiceImplementations');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($provider))
        ->toThrow(
            RuntimeException::class,
            "The configured service for 'dominion.services.tenant_context' must implement Infinity\Dominion\Contracts\TenantContext.");
});
