<?php

namespace Infinity\Dominion\Services;

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Exceptions\InvalidPolicyConfiguration;
use Infinity\Dominion\Exceptions\InvalidProfileConfiguration;
use UnitEnum;

class ConfigurationValidator
{
    /**
     * Create a new configuration validator instance.
     */
    public function __construct(
        protected AuthorizationCatalog $catalog,
    ) {}

    /**
     * Validate every configured assignment profile.
     */
    public function validateProfiles(): void
    {
        $profiles = config('dominion.profiles', []);

        if (! is_array($profiles)) {
            throw InvalidProfileConfiguration::for('profiles', 'the configuration value must be an array.');
        }

        foreach ($profiles as $name => $definition) {
            if (! is_string($name) || blank($name)) {
                throw InvalidProfileConfiguration::for((string) $name, 'profile names must be non-empty strings.');
            }

            $this->validateProfile($name, $definition);
        }
    }

    /**
     * Resolve and validate a named assignment profile.
     *
     * @return array{roles?: list<mixed>, permissions?: list<mixed>, denials?: list<mixed>}
     */
    public function profile(string $name): array
    {
        $profiles = config('dominion.profiles', []);

        if (! is_array($profiles) || ! array_key_exists($name, $profiles)) {
            throw InvalidProfileConfiguration::for($name, 'the profile is not configured.');
        }

        return $this->validateProfile($name, $profiles[$name]);
    }

    /**
     * Validate the default policy and model mappings.
     */
    public function validatePolicy(): void
    {
        $enabled = config('dominion.policy.enabled', true);
        $defaultPolicy = config('dominion.policy.class');
        $models = config('dominion.policy.models', []);

        if (! is_bool($enabled)) {
            throw InvalidPolicyConfiguration::for('enabled', 'the value must be a boolean.');
        }

        if (! $enabled) {
            return;
        }

        $this->validatePolicyClass($defaultPolicy, 'class');

        if (! is_array($models)) {
            throw InvalidPolicyConfiguration::for('models', 'the value must be an array.');
        }

        foreach ($models as $model => $configuredPolicy) {
            if (is_int($model)) {
                $this->validateModelClass($configuredPolicy, "models.{$model}");

                continue;
            }

            $this->validateModelClass($model, "models.{$model}");

            if (is_array($configuredPolicy)) {
                $unknown = array_diff(array_keys($configuredPolicy), ['policy']);

                if ($unknown !== []) {
                    throw InvalidPolicyConfiguration::for("models.{$model}", 'only the [policy] option is supported.');
                }

                $configuredPolicy = $configuredPolicy['policy'] ?? $defaultPolicy;
            }

            $this->validatePolicyClass($configuredPolicy, "models.{$model}");
        }
    }

    /**
     * Validate and normalize one assignment profile.
     *
     * @return array{roles?: list<mixed>, permissions?: list<mixed>, denials?: list<mixed>}
     */
    protected function validateProfile(string $name, mixed $definition): array
    {
        if (! is_array($definition)) {
            throw InvalidProfileConfiguration::for($name, 'the definition must be an array.');
        }

        $unknown = array_diff(array_keys($definition), ['roles', 'permissions', 'denials']);

        if ($unknown !== []) {
            throw InvalidProfileConfiguration::for($name, 'unsupported keys: '.implode(', ', $unknown).'.');
        }

        $normalized = [];
        $resolved = [];

        foreach (['roles', 'permissions', 'denials'] as $key) {
            $values = $definition[$key] ?? [];

            if (! is_array($values) || ! array_is_list($values)) {
                throw InvalidProfileConfiguration::for($name, "[{$key}] must be a list.");
            }

            foreach ($values as $value) {
                if (! is_string($value) && ! is_int($value) && ! $value instanceof UnitEnum && ! $value instanceof Model) {
                    throw InvalidProfileConfiguration::for($name, "[{$key}] contains an unsupported value of type ".get_debug_type($value).'.');
                }
            }

            $normalized[$key] = $values;
            $resolved[$key] = array_map(
                $key === 'roles' ? $this->catalog->resolveRole(...) : $this->catalog->resolvePermission(...),
                $values,
            );

            if (count($resolved[$key]) !== count(array_unique($resolved[$key]))) {
                throw InvalidProfileConfiguration::for($name, "[{$key}] contains duplicate values.");
            }
        }

        $this->validateDeclaredValues($name, 'roles', $resolved['roles'], $this->catalog->roles());
        $permissions = $this->catalog->permissions();
        $this->validateDeclaredValues($name, 'permissions', $resolved['permissions'], $permissions);
        $this->validateDeclaredValues($name, 'denials', $resolved['denials'], $permissions);

        $conflicts = array_intersect($resolved['permissions'], $resolved['denials']);

        if ($conflicts !== []) {
            throw InvalidProfileConfiguration::for($name, 'permissions and denials conflict for ['.implode(', ', $conflicts).'].');
        }

        return array_filter($normalized, fn (array $values): bool => $values !== []);
    }

    /**
     * Ensure profile values belong to the configured enum catalog.
     *
     * @param  list<string>  $values
     * @param  list<string>  $catalog
     */
    protected function validateDeclaredValues(string $profile, string $key, array $values, array $catalog): void
    {
        foreach ($values as $value) {
            if (! in_array($value, $catalog, true)) {
                throw InvalidProfileConfiguration::for($profile, "[{$key}] value [{$value}] is not declared in the catalog.");
            }
        }
    }

    /**
     * Validate an Eloquent model class.
     */
    protected function validateModelClass(mixed $model, string $key): void
    {
        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            throw InvalidPolicyConfiguration::for($key, 'the value must be an Eloquent model class.');
        }
    }

    /**
     * Validate a policy class name.
     */
    protected function validatePolicyClass(mixed $policy, string $key): void
    {
        if (! is_string($policy) || ! class_exists($policy)) {
            throw InvalidPolicyConfiguration::for($key, 'the value must be an existing policy class.');
        }
    }
}
