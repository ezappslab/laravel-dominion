<?php

namespace Infinity\Dominion\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Infinity\Dominion\Services\Catalog;
use Throwable;

/**
 * Validate Dominion's configuration and installed schema without mutating data.
 */
class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'dominion:doctor';

    /** @var string */
    protected $description = 'Diagnose Dominion configuration and catalog drift without changing state';

    /**
     * Run all configuration and database health checks.
     */
    public function handle(Catalog $catalog): int
    {
        try {
            $roles = $catalog->enumValues(config('dominion.enums.role'));
            $permissions = [];

            foreach (config('dominion.enums.permissions', []) as $enum) {
                $permissions = [...$permissions, ...$catalog->enumValues($enum)];
            }

            if (count($permissions) !== count(array_unique($permissions))) {
                throw new \InvalidArgumentException('Duplicate permission values detected.');
            }

            $allow = array_map($catalog->permission(...), config('dominion.defaults.allow', []));
            $deny = array_map($catalog->permission(...), config('dominion.defaults.deny', []));

            if (array_intersect($allow, $deny) !== []) {
                throw new \InvalidArgumentException('Default allow and deny lists overlap.');
            }

            $unknownDefaults = array_diff([...$allow, ...$deny], $permissions);

            if ($unknownDefaults !== []) {
                throw new \InvalidArgumentException('Unknown default permission ['.reset($unknownDefaults).'].');
            }

            foreach (config('dominion.profiles', []) as $name => $profile) {
                if (! is_array($profile)) {
                    throw new \InvalidArgumentException("Profile [{$name}] must be an array.");
                }

                foreach (['roles', 'allow', 'deny'] as $key) {
                    if (isset($profile[$key]) && ! is_iterable($profile[$key])) {
                        throw new \InvalidArgumentException("Profile [{$name}.{$key}] must be iterable.");
                    }
                }
            }

            foreach (config('dominion.tables') as $table) {
                if (! Schema::hasTable($table)) {
                    throw new \RuntimeException("Missing table [{$table}].");
                }
            }

            $this->components->info(sprintf('Configuration is valid: %d roles, %d permissions.', count($roles), count($permissions)));
            Artisan::call('dominion:sync', ['--dry-run' => true]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
