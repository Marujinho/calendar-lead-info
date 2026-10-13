<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Typed access to console input for commands in this module.
 *
 * Invariant: callers get a `string` (or `?string` for a valueless option),
 * never the `array|string|bool|null` union Symfony returns, so no command
 * casts an unexpected array or bool into a value it then stores.
 */
trait ReadsStringInput
{
    protected function stringArgument(string $key): string
    {
        $value = $this->argument($key);

        return is_string($value) ? $value : '';
    }

    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }
}
