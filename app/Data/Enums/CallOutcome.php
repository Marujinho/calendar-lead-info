<?php

declare(strict_types=1);

namespace App\Data\Enums;

/**
 * Result of one provider call as recorded in `provider_calls`.
 * RejectedCap means the call never reached the provider (A06 daily cap).
 */
enum CallOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Timeout = 'timeout';
    case RejectedCap = 'rejected_cap';

    public function reachedProvider(): bool
    {
        return $this !== self::RejectedCap;
    }
}
