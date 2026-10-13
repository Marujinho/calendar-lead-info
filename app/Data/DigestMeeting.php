<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * One of today's meetings in the digest. Times are already in the rep's zone.
 *
 * Invariant: `leads` is non-empty; meetings without leads never reach the digest.
 */
final readonly class DigestMeeting
{
    /**
     * @param  list<DigestLead>  $leads
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public CarbonImmutable $startAtLocal,
        public CarbonImmutable $endAtLocal,
        public array $leads,
    ) {}
}
