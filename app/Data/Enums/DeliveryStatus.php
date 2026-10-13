<?php

declare(strict_types=1);

namespace App\Data\Enums;

/**
 * State machine of one rep's digest for one local date.
 *
 * pending -> sending -> sent | failed_send
 * pending -> skipped_empty | failed_no_data
 *
 * Invariant: a row in Sending must never be sent again (PRD FR-12).
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case SkippedEmpty = 'skipped_empty';
    case FailedNoData = 'failed_no_data';
    case FailedSend = 'failed_send';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pending, self::Sending], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::FailedNoData, self::FailedSend], true);
    }
}
