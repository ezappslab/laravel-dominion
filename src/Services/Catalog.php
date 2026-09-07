<?php

namespace Infinity\Dominion\Services;

use BackedEnum;
use InvalidArgumentException;

/**
 * Normalizes application enum and scalar authorization values.
 */
class Catalog
{
    /**
     * Normalize a role enum case or scalar value.
     */
    public function role(mixed $value): string
    {
        return $this->normalize($value, 'role');
    }

    /**
     * Normalize a permission enum case or scalar value.
     */
    public function permission(mixed $value): string
    {
        return $this->normalize($value, 'permission');
    }

    /**
     * Convert a supported non-empty authorization value to its stored string.
     */
    public function normalize(mixed $value, string $kind): string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if ((! is_string($value) && ! is_int($value)) || (string) $value === '') {
            throw new InvalidArgumentException("Dominion {$kind} values must be non-empty backed enums, strings, or integers.");
        }

        return (string) $value;
    }

    /**
     * Extract normalized values from a configured backed enum class.
     *
     * @param  class-string|null  $enum
     * @return list<string>
     */
    public function enumValues(?string $enum): array
    {
        if ($enum === null) {
            return [];
        }

        if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException("[{$enum}] must be a backed enum.");
        }

        return array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
    }
}
