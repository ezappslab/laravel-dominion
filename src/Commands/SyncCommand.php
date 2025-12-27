<?php

namespace Infinity\Dominion\Commands;

use Illuminate\Console\Command;
use Infinity\Dominion\Contracts\PermissionValueResolver;
use Infinity\Dominion\Contracts\RoleValueResolver;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;

class SyncCommand extends Command
{
    /**
     * The signature for the 'dominion:sync' command.
     *
     * @property string $signature The command signature including options:
     *                             --dry-run: Display the changes without applying them.
     *                             --prune: Remove roles and permissions that are no longer defined in enums.
     *                             --sync-pivots: Sync role-permission assignments (stub).
     */
    protected $signature = 'dominion:sync 
                            {--dry-run : Display the changes without applying them} 
                            {--prune : Remove roles and permissions that are no longer defined in enums}
                            {--sync-pivots : Sync role-permission assignments (stub)}';

    /**
     * The description for the command.
     *
     * @property string $description Provides an explanation of the command's purpose:
     *                               Sync roles and permissions from defined enums to the database.
     */
    protected $description = 'Sync roles and permissions from defined enums to the database';

    /**
     * Handles the synchronization of roles and permissions, and optionally processes pivot synchronization.
     *
     * @param  RoleValueResolver  $roleResolver  Resolves role values to be synchronized.
     * @param  PermissionValueResolver  $permissionResolver  Resolves permission values to be synchronized.
     * @return int Returns a status code indicating the result of the operation.
     */
    public function handle(RoleValueResolver $roleResolver, PermissionValueResolver $permissionResolver): int
    {
        $this->syncRoles($roleResolver);
        $this->syncPermissions($permissionResolver);

        if ($this->option('sync-pivots')) {
            $this->info('Pivot syncing is currently a stub.');
        }

        $this->info('Dominion sync completed.');

        return self::SUCCESS;
    }

    /**
     * Synchronizes application roles using the provided resolver and configuration.
     *
     * This method retrieves the role enum configured in the application,
     * resolves the role names using the provided RoleValueResolver, and ensures
     * that the roles existing in the database are synchronized with the resolved roles.
     * New roles are created, existing roles are left unchanged, and if the prune option
     * is enabled, unused roles are deleted.
     *
     * @param  RoleValueResolver  $resolver  Instance responsible for resolving role values from enum cases.
     */
    protected function syncRoles(RoleValueResolver $resolver): void
    {
        $roleEnum = config('dominion.role_enum');

        if (! $roleEnum || ! enum_exists($roleEnum)) {
            $this->warn('No role enum configured or enum does not exist.');

            return;
        }

        $this->comment('Syncing roles...');

        $definedRoles = [];
        foreach ($roleEnum::cases() as $case) {
            $definedRoles[] = $resolver->resolve($case);
        }

        foreach ($definedRoles as $roleName) {
            if ($this->option('dry-run')) {
                $this->line("Would create/update role: {$roleName}");

                continue;
            }

            Role::firstOrCreate(['name' => $roleName]);
        }

        if ($this->option('prune')) {
            $rolesToPrune = Role::whereNotIn('name', $definedRoles)->get();

            foreach ($rolesToPrune as $role) {
                if ($this->option('dry-run')) {
                    $this->line("Would delete role: {$role->name}");

                    continue;
                }

                $role->delete();
            }
        }
    }

    /**
     * Synchronizes application permissions using the provided resolver and configuration.
     *
     * This method retrieves permission enums configured in the application,
     * resolves them using the provided PermissionValueResolver, and ensures
     * that the permissions existing in the database are synchronized with the
     * resolved permissions. New permissions are created, existing permissions
     * are left unchanged, and if the prune option is enabled, unused permissions
     * are deleted.
     *
     * @param  PermissionValueResolver  $resolver  Instance responsible for resolving permission values from enum cases.
     */
    protected function syncPermissions(PermissionValueResolver $resolver): void
    {
        $permissionEnums = config('dominion.permission_enums', []);

        if (empty($permissionEnums)) {
            $this->warn('No permission enums configured.');

            return;
        }

        $this->comment('Syncing permissions...');

        $definedPermissions = [];
        foreach ($permissionEnums as $enumClass) {
            if (! enum_exists($enumClass)) {
                $this->error("Enum class {$enumClass} does not exist.");

                continue;
            }

            foreach ($enumClass::cases() as $case) {
                $definedPermissions[] = $resolver->resolve($case);
            }
        }

        foreach ($definedPermissions as $permissionName) {
            if ($this->option('dry-run')) {
                $this->line("Would create/update permission: {$permissionName}");

                continue;
            }

            Permission::firstOrCreate(['name' => $permissionName]);
        }

        if ($this->option('prune')) {
            $permissionsToPrune = Permission::whereNotIn('name', $definedPermissions)->get();

            foreach ($permissionsToPrune as $permission) {
                if ($this->option('dry-run')) {
                    $this->line("Would delete permission: {$permission->name}");

                    continue;
                }

                $permission->delete();
            }
        }
    }
}
