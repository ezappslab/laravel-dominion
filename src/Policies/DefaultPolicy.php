<?php

namespace Infinity\Dominion\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Contracts\AuthorizationResolver;
use Infinity\Dominion\Contracts\TenantContext;

class DefaultPolicy
{
    /**
     * Handle dynamic method calls into the policy.
     *
     * Maps {model_table}.{ability} and delegates to AuthorizationResolver.
     */
    public function __call(string $ability, array $arguments): Response|bool
    {
        $user = $arguments[0] ?? null;
        $model = $arguments[1] ?? null;

        if (! $user instanceof Model || ! method_exists($user, 'hasPermission')) {
            return false;
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        $permission = $this->resolvePermissionName($ability, $model);

        return app(AuthorizationResolver::class)->hasPermission($user, $permission, $tenantId);
    }

    /**
     * Resolve the permission name based on ability and model.
     */
    protected function resolvePermissionName(string $ability, mixed $model): string
    {
        if ($model instanceof Model) {
            return $model->getTable().'.'.$ability;
        }

        if (is_string($model) && class_exists($model)) {
            $instance = new $model;
            if ($instance instanceof Model) {
                return $instance->getTable().'.'.$ability;
            }
        }

        return $ability;
    }
}
