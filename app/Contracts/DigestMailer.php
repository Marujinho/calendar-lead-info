<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\DigestData;
use App\Models\Rep;
use Carbon\CarbonImmutable;

/**
 * Hands a built digest to the mail transport.
 *
 * Separates "deciding to send" (SendDailyDigest) from "rendering the email"
 * (the DailyLeadBriefing mailable), so each can be tested without the other.
 * A non-null `$dataRefreshedAt` means the data is stale and the email must
 * carry the "last refreshed at" notice (PRD FR-11).
 */
interface DigestMailer
{
    public function send(Rep $rep, DigestData $digest, ?CarbonImmutable $dataRefreshedAt): void;
}
