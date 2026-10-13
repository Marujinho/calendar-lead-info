<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Opaque bag of per-tenant provider credentials.
 *
 * Invariant: never serialised into logs or exceptions. `__debugInfo` and
 * `__toString` are deliberately redacted so an accidental dump leaks nothing.
 */
final readonly class ProviderCredentials
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(private array $values = []) {}

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['values' => '[redacted]'];
    }

    public function __toString(): string
    {
        return 'ProviderCredentials([redacted])';
    }
}
