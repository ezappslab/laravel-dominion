<?php

namespace Infinity\Dominion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Infinity\Dominion\Commands\DoctorCommand;
use Infinity\Dominion\Commands\SyncCommand;
use Infinity\Dominion\Contracts\DominionManager as DominionManagerContract;
use Infinity\Dominion\Contracts\DominionPrincipal;
use Infinity\Dominion\Services\AuthorizationCache;
use Infinity\Dominion\Services\Catalog;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers Dominion's resources, services, commands, Gate, and policies.
 */
class DominionServiceProvider extends PackageServiceProvider
{
    /**
     * Describe the publishable package resources and console commands.
     */
    public function configurePackage(Package $package): void
    {
        $package->name('dominion')->hasConfigFile()->hasMigration('create_dominion_tables')
            ->hasCommands([SyncCommand::class, DoctorCommand::class])
            ->hasInstallCommand(fn (InstallCommand $command) => $command->publishConfigFile()->publishMigrations());
    }

    /**
     * Bind the facade contract to the package's default manager.
     */
    public function packageRegistered(): void
    {
        // Shared instances ensure command and facade invalidation clear the same memo.
        $this->app->singleton(Catalog::class);
        $this->app->singleton(AuthorizationCache::class);
        $this->app->singleton(DominionManagerContract::class, DominionManager::class);
    }

    /**
     * Connect opted-in principals and configured policies to Laravel's Gate.
     */
    public function packageBooted(): void
    {
        if (config('dominion.gate.enabled', true)) {
            Gate::before(function ($user, string $ability): ?bool {
                // Returning null leaves non-Dominion principals to Laravel's other gates.
                if (! $user instanceof Model || (! $user instanceof DominionPrincipal && ! method_exists($user, 'dominionAuthorized'))) {
                    return null;
                }

                return app(DominionManagerContract::class)->for($user)->globally()->isAllowed($ability);
            });
        }

        if (config('dominion.policy.enabled', true)) {
            foreach (config('dominion.policy.models', []) as $model => $policy) {
                Gate::policy($model, $policy);
            }
        }
    }
}
