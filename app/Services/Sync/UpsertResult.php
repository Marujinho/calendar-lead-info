<?php

declare(strict_types=1);

namespace App\Services\Sync;

/**
 * Row counts from applying one rep's week payload, for logging only.
 *
 * Invariant: carries counts, never emails or names, so it is always safe to
 * pass to JobLog.
 */
final readonly class UpsertResult
{
    public function __construct(
        public int $meetingsUpserted,
        public int $leadsUpserted,
        public int $detached,
        public int $removed,
    ) {}
}
