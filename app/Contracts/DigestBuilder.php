<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\DigestData;
use App\Models\Rep;
use Carbon\CarbonImmutable;

/**
 * Assembles one rep's digest for one rep-local date from stored data.
 *
 * Invariant: never calls a CalendarProvider (PRD FR-3, §13). Runs a bounded
 * number of queries regardless of lead count (NFR-2).
 */
interface DigestBuilder
{
    public function build(Rep $rep, CarbonImmutable $localDate): DigestData;
}
