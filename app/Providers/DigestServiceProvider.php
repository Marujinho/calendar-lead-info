<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Owned by the digest module: binds DigestBuilder to its Eloquent implementation.
 * Intentionally empty until that module lands.
 */
final class DigestServiceProvider extends ServiceProvider
{
    public function register(): void {}
}
