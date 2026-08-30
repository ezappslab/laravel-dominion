<?php

namespace Tests\Feature;

use Infinity\Dominion\Exceptions\InvalidPolicyConfiguration;
use Infinity\Dominion\Exceptions\InvalidProfileConfiguration;
use Infinity\Dominion\Policies\DefaultPolicy;
use Infinity\Dominion\Services\ConfigurationValidator;
use Tests\Support\Post;
use Tests\Support\TestPermission;
use Tests\Support\TestRole;

beforeEach(function (): void {
    config([
        'dominion.catalog.role_enum' => TestRole::class,
        'dominion.catalog.permission_enums' => [TestPermission::class],
    ]);
});

it('validates and resolves assignment profiles', function (): void {
    config(['dominion.profiles.member' => [
        'roles' => [TestRole::EDITOR],
        'permissions' => [TestPermission::CREATE],
    ]]);

    expect(app(ConfigurationValidator::class)->profile('member'))
        ->toBe([
            'roles' => [TestRole::EDITOR],
            'permissions' => [TestPermission::CREATE],
        ]);
});

it('rejects malformed and unknown profile values', function (mixed $profile, string $message): void {
    config(['dominion.profiles.member' => $profile]);

    expect(fn () => app(ConfigurationValidator::class)->profile('member'))
        ->toThrow(InvalidProfileConfiguration::class, $message);
})->with([
    'unsupported key' => [['role' => [TestRole::EDITOR]], 'unsupported keys: role'],
    'non-list roles' => [['roles' => ['editor' => TestRole::EDITOR]], '[roles] must be a list'],
    'non-array definition' => ['member', 'definition must be an array'],
    'unsupported value' => [['roles' => [true]], '[roles] contains an unsupported value of type bool'],
    'unknown role' => [['roles' => ['OWNER']], '[roles] value [OWNER] is not declared'],
    'unknown permission' => [['permissions' => ['posts.delete']], '[permissions] value [posts.delete] is not declared'],
    'duplicate permission' => [['permissions' => [TestPermission::CREATE, TestPermission::CREATE]], '[permissions] contains duplicate values'],
    'conflicting effects' => [[
        'permissions' => [TestPermission::CREATE],
        'denials' => [TestPermission::CREATE],
    ], 'permissions and denials conflict for [posts.create]'],
]);

it('rejects a missing assignment profile', function (): void {
    expect(fn () => app(ConfigurationValidator::class)->profile('missing'))
        ->toThrow(InvalidProfileConfiguration::class, 'the profile is not configured.');
});

it('validates every configured assignment profile', function (): void {
    config(['dominion.profiles' => [
        'member' => ['roles' => [TestRole::EDITOR]],
        'publisher' => ['permissions' => [TestPermission::CREATE]],
    ]]);

    app(ConfigurationValidator::class)->validateProfiles();

    expect(true)->toBeTrue();
});

it('rejects an invalid profiles collection', function (mixed $profiles, string $message): void {
    config(['dominion.profiles' => $profiles]);

    expect(fn () => app(ConfigurationValidator::class)->validateProfiles())
        ->toThrow(InvalidProfileConfiguration::class, $message);
})->with([
    'non-array collection' => ['member', 'configuration value must be an array'],
    'empty profile name' => [['' => []], 'profile names must be non-empty strings'],
    'numeric profile name' => [[[]], 'profile names must be non-empty strings'],
]);

it('validates policy model mappings', function (): void {
    config([
        'dominion.policy.class' => DefaultPolicy::class,
        'dominion.policy.models' => [
            Post::class,
            Post::class => DefaultPolicy::class,
        ],
    ]);

    app(ConfigurationValidator::class)->validatePolicy();

    expect(true)->toBeTrue();
});

it('uses the default policy for a model mapping without an override', function (): void {
    config([
        'dominion.policy.class' => DefaultPolicy::class,
        'dominion.policy.models' => [Post::class => []],
    ]);

    app(ConfigurationValidator::class)->validatePolicy();

    expect(true)->toBeTrue();
});

it('does not validate unused policy settings when policy integration is disabled', function (): void {
    config([
        'dominion.policy.enabled' => false,
        'dominion.policy.class' => 'App\\Policies\\MissingPolicy',
        'dominion.policy.models' => 'not-an-array',
    ]);

    app(ConfigurationValidator::class)->validatePolicy();

    expect(true)->toBeTrue();
});

it('rejects invalid policy configuration', function (mixed $value, string $key, string $message): void {
    config(["dominion.policy.{$key}" => $value]);

    expect(fn () => app(ConfigurationValidator::class)->validatePolicy())
        ->toThrow(InvalidPolicyConfiguration::class, $message);
})->with([
    'enabled flag' => ['yes', 'enabled', 'the value must be a boolean'],
    'default policy' => ['App\\Policies\\MissingPolicy', 'class', 'the value must be an existing policy class'],
    'models shape' => ['not-an-array', 'models', 'the value must be an array'],
    'model class' => [[DefaultPolicy::class], 'models', 'the value must be an Eloquent model class'],
    'mapped policy' => [[Post::class => 'App\\Policies\\MissingPolicy'], 'models', 'the value must be an existing policy class'],
    'mapping options' => [[Post::class => ['unexpected' => true]], 'models', 'only the [policy] option is supported'],
]);
