<?php

namespace Infinity\Dominion\Services;

use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Contracts\PermissionValueResolver;
use Infinity\Dominion\Contracts\RoleValueResolver;

class EnumAuthorizationCatalog implements AuthorizationCatalog
{
    public function __construct(
        protected PermissionValueResolver $permissionResolver,
        protected RoleValueResolver $roleResolver,
        protected ModelRegistry $models,
    ) {}

    public function resolvePermission(mixed $permission): string
    {
        $permissionModel = $this->models->permissionModel();

        if ($permission instanceof $permissionModel) {
            return $permission->name;
        }

        return $this->permissionResolver->resolve($permission);
    }

    public function resolveRole(mixed $role): string
    {
        $roleModel = $this->models->roleModel();

        if ($role instanceof $roleModel) {
            return $role->name;
        }

        return $this->roleResolver->resolve($role);
    }

    public function containsPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Return the unique permission names declared by configured enums.
     */
    public function permissions(): array
    {
        return $this->enumValues(config('dominion.catalog.permission_enums', []), $this->resolvePermission(...));
    }

    /**
     * Return the unique role names declared by the configured enum.
     */
    public function roles(): array
    {
        $enum = config('dominion.catalog.role_enum');

        return $this->enumValues(is_string($enum) ? [$enum] : [], $this->resolveRole(...));
    }

    /**
     * Resolve the configured role map, expanding wildcard permissions.
     */
    public function rolePermissions(): array
    {
        $configuredMap = config('dominion.catalog.role_permissions', []);
        $allPermissions = $this->permissions();
        $map = [];

        foreach ($configuredMap as $role => $permissions) {
            $roleName = $this->resolveRole($role);
            $resolved = in_array('*', $permissions, true)
                ? $allPermissions
                : array_map($this->resolvePermission(...), $permissions);

            $map[$roleName] = array_values(array_unique($resolved));
        }

        return $map;
    }

    /**
     * @param  array<array-key, mixed>  $enums
     * @param  callable(mixed): string  $resolver
     * @return list<string>
     */
    private function enumValues(array $enums, callable $resolver): array
    {
        $values = [];

        foreach ($enums as $enum) {
            if (! is_string($enum) || ! enum_exists($enum)) {
                continue;
            }

            foreach ($enum::cases() as $case) {
                $values[] = $resolver($case);
            }
        }

        return array_values(array_unique($values));
    }
}
