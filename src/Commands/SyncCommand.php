<?php

namespace Infinity\Dominion\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Infinity\Dominion\Services\AuthorizationCache;
use Infinity\Dominion\Services\Catalog;
use InvalidArgumentException;

/**
 * Materialize the configured enum catalog, defaults, and role mappings.
 */
class SyncCommand extends Command
{
    /** @var string */
    protected $signature = 'dominion:sync {--dry-run} {--prune}';

    /** @var string */
    protected $description = 'Synchronize Dominion enums, defaults, and role permissions';

    /**
     * Synchronize configuration to the database as one atomic operation.
     */
    public function handle(Catalog $catalog, AuthorizationCache $cache): int
    {
        $roles = $catalog->enumValues(config('dominion.enums.role'));
        $permissions = [];

        foreach (config('dominion.enums.permissions', []) as $enum) {
            $permissions = [...$permissions, ...$catalog->enumValues($enum)];
        }

        if (count($permissions) !== count(array_unique($permissions))) {
            throw new InvalidArgumentException('Permission enum values must be unique across the catalog.');
        }

        $allow = array_map($catalog->permission(...), config('dominion.defaults.allow', []));
        $deny = array_map($catalog->permission(...), config('dominion.defaults.deny', []));

        if (array_intersect($allow, $deny) !== []) {
            throw new InvalidArgumentException('A permission cannot be both allowed and denied by default.');
        }

        $unknownDefaults = array_diff([...$allow, ...$deny], $permissions);

        if ($unknownDefaults !== []) {
            throw new InvalidArgumentException('Unknown default permission ['.reset($unknownDefaults).'].');
        }

        $this->line(sprintf('%s %d roles and %d permissions.', $this->option('dry-run') ? 'Would synchronize' : 'Synchronizing', count($roles), count($permissions)));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $roleClass = config('dominion.models.role');
        $permissionClass = config('dominion.models.permission');

        // Catalog rows and their pivot mappings must become visible together.
        DB::transaction(function () use ($roles, $permissions, $allow, $deny, $roleClass, $permissionClass, $catalog): void {
            foreach ($roles as $name) {
                $roleClass::query()->updateOrCreate(['name' => $name]);
            }

            foreach ($permissions as $name) {
                $permissionClass::query()->updateOrCreate(['name' => $name], ['default_effect' => in_array($name, $allow, true) ? 'allow' : (in_array($name, $deny, true) ? 'deny' : null)]);
            }

            if ($this->option('prune')) {
                $roleClass::query()->when($roles !== [], fn ($q) => $q->whereNotIn('name', $roles))->when($roles === [], fn ($q) => $q)->delete();
                $permissionClass::query()->when($permissions !== [], fn ($q) => $q->whereNotIn('name', $permissions))->when($permissions === [], fn ($q) => $q)->delete();
            }

            $roleIds = $roleClass::query()->pluck('id', 'name');
            $permissionIds = $permissionClass::query()->pluck('id', 'name');
            $pivot = config('dominion.tables.role_permissions');
            $roleMap = config('dominion.roles', []);

            // Validate map keys before clearing every synchronized role's pivot rows.
            foreach (array_keys($roleMap) as $configuredRole) {
                $configuredRole = $catalog->role($configuredRole);

                if (! isset($roleIds[$configuredRole])) {
                    throw new InvalidArgumentException("Unknown configured role [{$configuredRole}].");
                }
            }

            foreach ($roles as $role) {
                $mapped = $roleMap[$role] ?? [];
                $values = in_array('*', $mapped, true) ? $permissions : array_map($catalog->permission(...), $mapped);

                DB::table($pivot)->where('role_id', $roleIds[$role])->delete();

                foreach ($values as $permission) {
                    if (! isset($permissionIds[$permission])) {
                        throw new InvalidArgumentException("Unknown configured permission [{$permission}].");
                    }

                    DB::table($pivot)->insert(['role_id' => $roleIds[$role], 'permission_id' => $permissionIds[$permission]]);
                }
            }
        });

        $cache->invalidateCatalog();
        $this->info('Dominion catalog synchronized.');

        return self::SUCCESS;
    }
}
