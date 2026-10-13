<?php

declare(strict_types=1);

namespace App\Data\Enums;

/**
 * Why a provider call was made. Steady-state cost (G2) is measured on Sync only.
 */
enum CallPurpose: string
{
    case Sync = 'sync';
    case Backfill = 'backfill';
}
