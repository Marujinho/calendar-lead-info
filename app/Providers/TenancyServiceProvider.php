<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh context per request, queued job and artisan invocation.
        $this->app->scoped(TenantContext::class);
    }
}
