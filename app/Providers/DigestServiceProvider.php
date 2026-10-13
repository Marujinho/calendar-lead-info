<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\DigestBuilder;
use App\Contracts\DigestMailer;
use App\Services\Digest\EloquentDigestBuilder;
use App\Services\Digest\MailableDigestMailer;
use Illuminate\Support\ServiceProvider;

/**
 * Owned by the digest module: binds DigestBuilder to its Eloquent
 * implementation and DigestMailer to the DailyLeadBriefing mailable, so the
 * send job depends on interfaces only.
 */
final class DigestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DigestBuilder::class, EloquentDigestBuilder::class);
        $this->app->bind(DigestMailer::class, MailableDigestMailer::class);
    }
}
