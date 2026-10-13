<?php

declare(strict_types=1);

namespace App\Data\Enums;

/**
 * Lifecycle state of a meeting as reported by the calendar provider.
 *
 * Invariant: only Confirmed and Tentative meetings count as "held" for digests
 * and the 30-day history. Cancelled meetings are stored (so re-syncs stay
 * idempotent) but never counted.
 */
enum MeetingStatus: string
{
    case Confirmed = 'confirmed';
    case Tentative = 'tentative';
    case Cancelled = 'cancelled';

    public function isHeld(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * @return list<string>
     */
    public static function heldValues(): array
    {
        return [self::Confirmed->value, self::Tentative->value];
    }
}
