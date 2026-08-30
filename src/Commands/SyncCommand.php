<?php

namespace Infinity\Dominion\Commands;

use Illuminate\Console\Command;
use Infinity\Dominion\Contracts\AuthorizationCache;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Events\CatalogSynchronized;
use Infinity\Dominion\Services\DominionDatabase;
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
        DominionDatabase $database,
    ): int {
        $snapshot = $catalog->snapshot();
        $roles = $snapshot->roles;
        $permissions = $snapshot->permissions;
        $map = $snapshot->rolePermissions;
        $prune = $this->option('prune') || (bool) config('dominion.catalog.prune', false);

        if ($roles === [] && $permissions === [] && ! $prune) {
            $this->warn('No Dominion role or permission enums are configured.');

            return self::SUCCESS;
        }

        $this->displayPlan($roles, $permissions, $map);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $database->connection()->transaction(function () use ($roles, $permissions, $map, $models, $cache, $prune, $database): void {
            $roleModel = $models->roleModel();
            $permissionModel = $models->permissionModel();
            $timestamp = now();

            if ($roles !== []) {
                $roleModel::query()->insertOrIgnore(array_map(
                    fn (string $role): array => ['name' => $role, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                    $roles,
                ));
            }

            if ($permissions !== []) {
                $permissionModel::query()->insertOrIgnore(array_map(
                    fn (string $permission): array => ['name' => $permission, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                    $permissions,
                ));
            }

            $roleIds = $roleModel::query()->whereIn('name', $roles)->pluck('id', 'name')->all();
            $permissionIds = $permissionModel::query()->whereIn('name', $permissions)->pluck('id', 'name')->all();
            $relation = (new $roleModel)->permissions();
            $connection = $database->connection();
            $pivot = $connection->table($relation->getTable());
            $roleForeignKey = $relation->getForeignPivotKeyName();
            $permissionForeignKey = $relation->getRelatedPivotKeyName();
            $pivotRows = [];

            foreach ($roleIds as $roleName => $roleId) {
                foreach ($map[$roleName] ?? [] as $permissionName) {
                    $pivotRows[] = [
                        $roleForeignKey => $roleId,
                        $permissionForeignKey => $permissionIds[$permissionName],
                        $relation->createdAt() => $timestamp,
                        $relation->updatedAt() => $timestamp,
                    ];
                }
            }

            if ($roleIds !== []) {
                $pivot->whereIn($roleForeignKey, array_values($roleIds))->delete();
            }

            foreach (array_chunk($pivotRows, 500) as $pivotChunk) {
                $pivot->insert($pivotChunk);
            }

            if ($prune) {
                $roles === []
                    ? $roleModel::query()->delete()
                    : $roleModel::query()->whereNotIn('name', $roles)->delete();
                $permissions === []
                    ? $permissionModel::query()->delete()
                    : $permissionModel::query()->whereNotIn('name', $permissions)->delete();
            }

            $connection->afterCommit(function () use ($cache, $roles, $permissions, $map, $prune): void {
                $cache->invalidateCatalog();
                event(new CatalogSynchronized($roles, $permissions, $map, $prune));
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
