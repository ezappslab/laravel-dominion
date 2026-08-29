<?php

namespace Infinity\Dominion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Infinity\Dominion\Commands\SyncCommand;
use Infinity\Dominion\Contracts\AuthorizationCache as AuthorizationCacheContract;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Contracts\AuthorizationResolver;
use Infinity\Dominion\Contracts\PermissionValueResolver;
use Infinity\Dominion\Contracts\RoleValueResolver;
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Domain\AuthorizationDecision;
use Infinity\Dominion\Services\AuthorizationCache;
use Infinity\Dominion\Services\DefaultAuthorizationResolver;
use Infinity\Dominion\Services\DefaultPermissionValueResolver;
use Infinity\Dominion\Services\DefaultRoleValueResolver;
use Infinity\Dominion\Services\DefaultTenantContext;
use Infinity\Dominion\Services\EnumAuthorizationCatalog;
use RuntimeException;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DominionServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('dominion')
            ->hasConfigFile()
            ->hasMigrations([
                'create_dominion_tables',
            ])
            ->hasCommand(SyncCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations();
            });
    }

    /**
     * Registers package-specific singleton bindings in the application container.
     *
     * This method establishes singleton bindings for various service classes required by the package. For each service, it retrieves
     * the fully qualified class name from the package's configuration file and binds it to the application container as a singleton.
     * These bindings ensure that the same instance of each service is shared across the application.
     */
    public function packageRegistered(): void
    {
        $this->bindConfiguredSingleton(TenantContext::class, 'tenant_context');
        $this->bindConfiguredSingleton(PermissionValueResolver::class, 'permission_value_resolver');
        $this->bindConfiguredSingleton(RoleValueResolver::class, 'role_value_resolver');
        $this->bindConfiguredSingleton(AuthorizationCatalog::class, 'authorization_catalog');
        $this->bindConfiguredSingleton(AuthorizationResolver::class, 'authorization_resolver');
        $this->app->singleton(AuthorizationCacheContract::class, AuthorizationCache::class);
    }

    /**
     * Executes tasks that should be performed after the service provider's package has been booted.
     *
     * This method ensures that the application's service layer is prepared by validating service implementations
     * and registering the necessary authorization policies.
     */
    public function packageBooted(): void
    {
        $this->validateServiceImplementations();
        if ((bool) config('dominion.policy.enabled', true)) {
            $this->registerPolicies();
        }

        if ((bool) config('dominion.gate.enabled', true)) {
            $this->registerGateBefore();
        }
    }

    /**
     * Registers a "before" callback for the authorization gate to intercept permission checks.
     *
     * This method adds a callback to the Gate that will execute before any ability-based authorization logic.
     * The callback checks if the user object has a `hasPermission` method, and if so, calls it with the requested ability.
     * If the user does not have the `hasPermission` method, the callback returns null, allowing the default authorization logic to proceed.
     */
    protected function registerGateBefore(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof Model) {
                return null;
            }

            $decision = app(AuthorizationResolver::class)->decide(
                $user,
                $ability,
                app(TenantContext::class)->currentScope(),
            );

            if ($decision === AuthorizationDecision::Abstain && config('dominion.gate.unknown_ability') === 'deny') {
                return false;
            }

            return $decision->toGateResult();
        });
    }

    /**
     * Registers policies for the specified models using the configured policy class.
     *
     * This method retrieves the policy class and the list of models from the configuration.
     * It then associates the policy class with each model in the list by registering it with the authorization Gate.
     */
    protected function registerPolicies(): void
    {
        $policyClass = config('dominion.policy.class');
        $models = config('dominion.policy.models', []);

        foreach ($models as $model => $configuredPolicy) {
            if (is_int($model)) {
                Gate::policy($configuredPolicy, $policyClass);

                continue;
            }

            $policy = is_array($configuredPolicy) ? ($configuredPolicy['policy'] ?? $policyClass) : $configuredPolicy;
            Gate::policy($model, $policy);
        }
    }

    /**
     * Validates that the configured service implementations conform to their respective contract interfaces.
     *
     * This method iterates through a predefined list of service contract-to-configuration key mappings. For each mapping,
     * it resolves the service instance from the application container and checks if it implements the expected contract.
     * If a service does not implement its contract, an exception is thrown indicating the misconfiguration.
     *
     * @throws RuntimeException if a service does not implement its contract
     */
    protected function validateServiceImplementations(): void
    {
        $services = [
            TenantContext::class => 'tenant_context',
            PermissionValueResolver::class => 'permission_value_resolver',
            RoleValueResolver::class => 'role_value_resolver',
            AuthorizationResolver::class => 'authorization_resolver',
            AuthorizationCatalog::class => 'authorization_catalog',
        ];

        foreach ($services as $contract => $configKey) {
            $implementation = config("dominion.services.{$configKey}", $this->defaultService($configKey));

            if (! is_string($implementation) || ! is_a($implementation, $contract, true)) {
                throw new RuntimeException("The configured service for 'dominion.services.{$configKey}' must implement {$contract}.");
            }
        }
    }

    /** @param  class-string  $contract */
    protected function bindConfiguredSingleton(string $contract, string $configKey): void
    {
        $this->app->singleton($contract, function ($app) use ($configKey): object {
            $class = config("dominion.services.{$configKey}", $this->defaultService($configKey));

            if (! is_string($class)) {
                throw new RuntimeException("The configured Dominion service [{$configKey}] must be a class name.");
            }

            return $app->make($class);
        });
    }

    /** @return class-string */
    protected function defaultService(string $configKey): string
    {
        return match ($configKey) {
            'tenant_context' => DefaultTenantContext::class,
            'permission_value_resolver' => DefaultPermissionValueResolver::class,
            'role_value_resolver' => DefaultRoleValueResolver::class,
            'authorization_catalog' => EnumAuthorizationCatalog::class,
            'authorization_resolver' => DefaultAuthorizationResolver::class,
            default => throw new RuntimeException("Unknown Dominion service [{$configKey}]."),
        };
    }
}
