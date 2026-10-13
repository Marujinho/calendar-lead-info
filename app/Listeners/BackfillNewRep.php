<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\RepCreated;
use App\Jobs\BackfillRep;

/**
 * Starts the one-time history backfill when a rep is created (PRD FR-14).
 *
 * Invariant: only dispatches; the provider is never called in the command
 * that created the rep, so `rep:add` / `rep:import` stay fast and the call
 * goes through the `sync` queue like every other fetch.
 */
final class BackfillNewRep
{
    public function handle(RepCreated $event): void
    {
        BackfillRep::dispatch($event->tenantId, $event->repId);
    }
}
