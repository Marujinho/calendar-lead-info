<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Enums\ResponseStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot: which leads attended a meeting and how they responded. A Declined
 * response keeps the link (for idempotent re-sync) but excludes the lead from
 * digests and counts.
 *
 * @property int $tenant_id
 * @property int $meeting_id
 * @property int $lead_id
 * @property ResponseStatus $response_status
 */
final class MeetingLead extends Pivot
{
    use BelongsToTenant;

    protected $table = 'meeting_lead';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['response_status' => ResponseStatus::class];
    }
}
