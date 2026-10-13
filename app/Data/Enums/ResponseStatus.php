<?php

declare(strict_types=1);

namespace App\Data\Enums;

/**
 * An attendee's reply to a meeting invitation, mirroring the Google Calendar
 * attendee `responseStatus` enum so a real adapter maps it one-to-one.
 *
 * Invariant: a Declined attendee is never a lead for that meeting.
 */
enum ResponseStatus: string
{
    case NeedsAction = 'needsAction';
    case Declined = 'declined';
    case Tentative = 'tentative';
    case Accepted = 'accepted';

    public function countsAsAttending(): bool
    {
        return $this !== self::Declined;
    }
}
