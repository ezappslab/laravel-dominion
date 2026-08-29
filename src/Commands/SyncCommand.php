<?php

namespace Infinity\Dominion\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Infinity\Dominion\Contracts\AuthorizationCache;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Events\CatalogSynchronized;
use Infinity\Dominion\Services\ModelRegistry;

class SyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'dominion:sync
                            {--dry-run : Display changes without applying them}
                            {--prune : Delete catalog entries absent from the configured enums}';

    /**
     * The console command description.
     */
    protected $description = 'Synchronize Dominion roles, permissions, and role mappings from application enums';

    /**
     * Execute the console command.
     */
    public function handle(
        AuthorizationCatalog $catalog,
        ModelRegistry $models,
        AuthorizationCache $cache,
    ): int {
        $snapshot = $catalog->snapshot();
        $roles = $snapshot->roles;
        $permissions = $snapshot->permissions;
        $map = $snapshot->rolePermissions;

        if ($roles === [] && $permissions === []) {
            $this->warn('No Dominion role or permission enums are configured.');

            return self::SUCCESS;
        }

        $this->displayPlan($roles, $permissions, $map);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $prune = $this->option('prune') || (bool) config('dominion.catalog.prune', false);

        DB::transaction(function () use ($roles, $permissions, $map, $models, $cache, $prune): void {
            $roleModel = $models->roleModel();
            $permissionModel = $models->permissionModel();

            foreach ($roles as $role) {
                $roleModel::query()->firstOrCreate(['name' => $role]);
            }

            foreach ($permissions as $permission) {
                $permissionModel::query()->firstOrCreate(['name' => $permission]);
            }

            foreach ($map as $roleName => $permissionNames) {
                $role = $roleModel::query()->where('name', $roleName)->first();

                if ($role === null) {
                    continue;
                }

                $permissionIds = $permissionModel::query()->whereIn('name', $permissionNames)->pluck('id')->all();
                $role->permissions()->sync($permissionIds);
            }

            if ($prune) {
                $roleModel::query()->whereNotIn('name', $roles)->delete();
                $permissionModel::query()->whereNotIn('name', $permissions)->delete();
            }

            DB::afterCommit(function () use ($cache, $roles, $permissions, $map, $prune): void {
                $cache->invalidateCatalog();
                Event::dispatch(new CatalogSynchronized($roles, $permissions, $map, $prune));
            });
        });

        $this->info('Dominion catalog synchronized.');

        return self::SUCCESS;
    }

    /**
     * Display the catalog synchronization summary.
     *
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     * @param  array<string, list<string>>  $map
     */
    protected function displayPlan(array $roles, array $permissions, array $map): void
    {
        $this->line(sprintf(
            '%s %d roles, %d permissions, and %d role mappings.',
            $this->option('dry-run') ? 'Would synchronize' : 'Synchronizing',
            count($roles),
            count($permissions),
            count($map),
        ));
    }
}
