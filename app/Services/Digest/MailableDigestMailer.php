<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Contracts\DigestMailer;
use App\Data\DigestData;
use App\Mail\DailyLeadBriefing;
use App\Models\Rep;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a built digest to its rep through the DailyLeadBriefing mailable.
 *
 * Invariants: the only recipient is the rep's own address, and the mail is
 * sent synchronously (the calling job owns queuing, retries and the delivery
 * state machine). A non-null `$dataRefreshedAt` is passed through unchanged
 * so the email carries the stale notice (FR-11).
 */
final class MailableDigestMailer implements DigestMailer
{
    public function send(Rep $rep, DigestData $digest, ?CarbonImmutable $dataRefreshedAt): void
    {
        Mail::to($rep->email)->send(new DailyLeadBriefing($digest, $rep, $dataRefreshedAt));
    }
}
