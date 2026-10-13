<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot: which reps' calendars returned a meeting. Carries tenant_id so the
 * TenantScope applies to pivot queries too (FR-16).
 *
 * @property int $tenant_id
 * @property int $meeting_id
 * @property int $rep_id
 */
final class MeetingRep extends Pivot
{
    use BelongsToTenant;

    protected $table = 'meeting_rep';

    public $incrementing = true;
}
