<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by the rep:add / rep:import commands once a rep row exists.
 * The sync module listens and dispatches the one-time backfill (FR-14).
 * Carries ids only.
 */
final class RepCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $repId,
    ) {}
}
