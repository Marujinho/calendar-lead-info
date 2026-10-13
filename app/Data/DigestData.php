<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * The content of one rep's digest for one local date, built from stored data
 * only (never from a live provider call).
 *
 * Freshness is deliberately not part of this object: the send job decides
 * stale/no-data from `rep_week_syncs` and passes the notice to the mailable.
 */
final readonly class DigestData
{
    /**
     * @param  list<DigestMeeting>  $meetings  ordered by start time
     */
    public function __construct(
        public int $repId,
        public CarbonImmutable $localDate,
        public string $timezone,
        public array $meetings,
    ) {}

    public function isEmpty(): bool
    {
        return $this->meetings === [];
    }

    public function leadCount(): int
    {
        return array_sum(array_map(static fn (DigestMeeting $m): int => count($m->leads), $this->meetings));
    }
}
