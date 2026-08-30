<?php

namespace Infinity\Dominion\Services;

use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Contracts\PermissionValueResolver;
use Infinity\Dominion\Contracts\RoleValueResolver;
use Infinity\Dominion\Domain\AuthorizationCatalogSnapshot;
use Infinity\Dominion\Exceptions\InvalidCatalogConfiguration;

class EnumAuthorizationCatalog implements AuthorizationCatalog
{
    /**
     * Create a new enum-backed authorization catalog instance.
     */
    public function __construct(
        protected PermissionValueResolver $permissionResolver,
        protected RoleValueResolver $roleResolver,
        protected ModelRegistry $models,
    ) {}

    /**
     * Build one validated snapshot of the configured catalog.
     */
    public function snapshot(): AuthorizationCatalogSnapshot
    {
        $permissions = $this->configuredPermissions();
        $roles = $this->configuredRoles();

        return new AuthorizationCatalogSnapshot(
            roles: $roles,
            permissions: $permissions,
            rolePermissions: $this->configuredRolePermissions($roles, $permissions),
        );
    }

    /**
     * Resolve a permission model, enum, or scalar to its catalog name.
     */
    public function resolvePermission(mixed $permission): string
    {
        $permissionModel = $this->models->permissionModel();

        if ($permission instanceof $permissionModel) {
            return $permission->name;
        }

        return $this->permissionResolver->resolve($permission);
    }

    /**
     * Resolve a role model, enum, or scalar to its catalog name.
     */
    public function resolveRole(mixed $role): string
    {
        $roleModel = $this->models->roleModel();

        if ($role instanceof $roleModel) {
            return $role->name;
        }

        return $this->roleResolver->resolve($role);
    }

    /**
     * Return the unique permission names declared by configured enums.
     */
    public function permissions(): array
    {
        return $this->configuredPermissions();
    }

    /**
     * Resolve and validate the configured permission enums.
     *
     * @return list<string>
     */
    protected function configuredPermissions(): array
    {
        $enums = config('dominion.catalog.permission_enums', []);

        if (! is_array($enums)) {
            throw InvalidCatalogConfiguration::for('permission_enums', 'the value must be an array of enum class names.');
        }

        return $this->enumValues($enums, $this->resolvePermission(...), 'permission_enums');
    }

    /**
     * Return the unique role names declared by the configured enum.
     */
    public function roles(): array
    {
        return $this->configuredRoles();
    }

    /**
     * Resolve and validate the configured role enum.
     *
     * @return list<string>
     */
    protected function configuredRoles(): array
    {
        $enum = config('dominion.catalog.role_enum');

        if ($enum === null) {
            return [];
        }

        if (! is_string($enum)) {
            throw InvalidCatalogConfiguration::for('role_enum', 'the value must be an enum class name or null.');
        }

        return $this->enumValues([$enum], $this->resolveRole(...), 'role_enum');
    }

    /**
     * Resolve the configured role map, expanding wildcard permissions.
     */
    public function rolePermissions(): array
    {
        return $this->configuredRolePermissions(
            $this->configuredRoles(),
            $this->configuredPermissions(),
        );
    }

    /**
     * Resolve and validate role mappings against resolved catalog values.
     *
     * @param  list<string>  $allRoles
     * @param  list<string>  $allPermissions
     * @return array<string, list<string>>
     */
    protected function configuredRolePermissions(array $allRoles, array $allPermissions): array
    {
        $configuredMap = config('dominion.catalog.role_permissions', []);

        if (! is_array($configuredMap)) {
            throw InvalidCatalogConfiguration::for('role_permissions', 'the value must be an array keyed by role name.');
        }

        $map = [];

        foreach ($configuredMap as $role => $permissions) {
            $roleName = $this->resolveRole($role);

            if (! in_array($roleName, $allRoles, true)) {
                throw InvalidCatalogConfiguration::for('role_permissions', "role [{$roleName}] is not declared by the configured role enum.");
            }

            if (! is_array($permissions)) {
                throw InvalidCatalogConfiguration::for('role_permissions', "permissions for role [{$roleName}] must be an array.");
            }

            if (in_array('*', $permissions, true)) {
                if ($permissions !== ['*']) {
                    throw InvalidCatalogConfiguration::for('role_permissions', "the wildcard for role [{$roleName}] must be the only permission value.");
                }

                $map[$roleName] = $allPermissions;

                continue;
            }

            $resolved = array_map($this->resolvePermission(...), $permissions);

            foreach ($resolved as $permission) {
                if (! in_array($permission, $allPermissions, true)) {
                    throw InvalidCatalogConfiguration::for('role_permissions', "permission [{$permission}] for role [{$roleName}] is not declared by a configured permission enum.");
                }
            }

            if (count($resolved) !== count(array_unique($resolved))) {
                throw InvalidCatalogConfiguration::for('role_permissions', "role [{$roleName}] contains duplicate permissions.");
            }

            $map[$roleName] = array_values($resolved);
        }

        return $map;
    }

    /**
     * Extract unique values from the configured enum classes.
     *
     * @param  array<array-key, mixed>  $enums
     * @param  callable(mixed): string  $resolver
     * @return list<string>
     */
    private function enumValues(array $enums, callable $resolver, string $configKey): array
    {
        $values = [];

        foreach ($enums as $enum) {
            if (! is_string($enum) || ! enum_exists($enum)) {
                $value = is_string($enum) ? $enum : get_debug_type($enum);

                throw InvalidCatalogConfiguration::for($configKey, "[{$value}] is not an enum class.");
            }

            foreach ($enum::cases() as $case) {
                $value = $resolver($case);

                if (blank($value)) {
                    throw InvalidCatalogConfiguration::for($configKey, "enum [{$enum}] contains an empty value.");
                }

                if (in_array($value, $values, true)) {
                    throw InvalidCatalogConfiguration::for($configKey, "permission or role value [{$value}] is declared more than once.");
                }

                $values[] = $value;
            }
        }

        return $values;
    }
}
