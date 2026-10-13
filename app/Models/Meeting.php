<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Enums\MeetingStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One provider meeting, stored once per tenant and shared by every rep whose
 * calendar returned it (identity: tenant_id + external_id).
 *
 * `removed_at` is set only when no rep is attached any more (FR-5). The
 * `held()` scope is the single definition of "counts for digests and history".
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $external_id
 * @property string $title
 * @property CarbonImmutable $start_at
 * @property CarbonImmutable $end_at
 * @property MeetingStatus $status
 * @property CarbonImmutable|null $removed_at
 */
final class Meeting extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'external_id', 'title', 'start_at', 'end_at', 'status', 'removed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'status' => MeetingStatus::class,
            'removed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Not cancelled and not removed.
     *
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->whereIn('status', MeetingStatus::heldValues())->whereNull('removed_at');
    }

    /**
     * @return BelongsToMany<Rep, $this, MeetingRep>
     */
    public function reps(): BelongsToMany
    {
        return $this->belongsToMany(Rep::class, 'meeting_rep')->using(MeetingRep::class)->withPivot('tenant_id');
    }

    /**
     * @return BelongsToMany<Lead, $this, MeetingLead>
     */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'meeting_lead')
            ->using(MeetingLead::class)
            ->withPivot(['tenant_id', 'response_status']);
    }
}
