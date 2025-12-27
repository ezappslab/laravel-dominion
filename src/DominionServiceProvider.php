<?php

namespace Infinity\Dominion;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Gate;
use Infinity\Dominion\Commands\SyncCommand;
use Infinity\Dominion\Contracts\AuthorizationResolver;
use Infinity\Dominion\Contracts\PermissionValueResolver;
use Infinity\Dominion\Contracts\RoleValueResolver;
use Infinity\Dominion\Contracts\TenantContext;
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
            ->hasInstallCommand(function (InstallCommand $command) {
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
        $this->app->singleton(TenantContext::class, function ($app) {
            $class = config('dominion.services.tenant_context');

            return new $class;
        });

        $this->app->singleton(PermissionValueResolver::class, function ($app) {
            $class = config('dominion.services.permission_value_resolver');

            return new $class;
        });

        $this->app->singleton(RoleValueResolver::class, function ($app) {
            $class = config('dominion.services.role_value_resolver');

            return new $class;
        });

        $this->app->singleton(AuthorizationResolver::class, function ($app) {
            $class = config('dominion.services.authorization_resolver');

            return new $class;
        });
    }

    /**
     * Executes tasks that should be performed after the service provider's package has been booted.
     *
     * This method ensures that the application's service layer is prepared by validating service implementations
     * and registering the necessary authorization policies.
     *
     * @throws BindingResolutionException
     */
    public function packageBooted(): void
    {
        $this->validateServiceImplementations();
        $this->registerPolicies();
        $this->registerGateBefore();
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
        Gate::before(function ($user, $ability) {
            if (method_exists($user, 'hasPermission')) {
                return $user->hasPermission($ability);
            }

            return null;
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

        foreach ($models as $model) {
            Gate::policy($model, $policyClass);
        }
    }

    /**
     * Validates that the configured service implementations conform to their respective contract interfaces.
     *
     * This method iterates through a predefined list of service contract-to-configuration key mappings. For each mapping,
     * it resolves the service instance from the application container and checks if it implements the expected contract.
     * If a service does not implement its contract, an exception is thrown indicating the misconfiguration.
     *
     * @throws RuntimeException|BindingResolutionException if a service does not implement its contract
     */
    protected function validateServiceImplementations(): void
    {
        $services = [
            TenantContext::class => 'tenant_context',
            PermissionValueResolver::class => 'permission_value_resolver',
            RoleValueResolver::class => 'role_value_resolver',
            AuthorizationResolver::class => 'authorization_resolver',
        ];

        foreach ($services as $contract => $configKey) {
            $instance = $this->app->make($contract);

            if (! $instance instanceof $contract) {
                throw new RuntimeException("The configured service for 'dominion.services.{$configKey}' must implement {$contract}.");
            }
        }
    }
}
