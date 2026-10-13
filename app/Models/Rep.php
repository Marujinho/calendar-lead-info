<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\RepClock;
use Carbon\CarbonImmutable;
use Database\Factories\RepFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales rep who receives the daily digest.
 *
 * `email` is unique per tenant and stored lower-cased. `active = false` is the
 * per-rep kill switch: inactive reps are neither synced nor emailed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property string $name
 * @property string $timezone
 * @property bool $active
 * @property CarbonImmutable|null $backfilled_at
 */
final class Rep extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RepFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'email', 'name', 'timezone', 'active', 'backfilled_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'backfilled_at' => 'immutable_datetime',
        ];
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    /**
     * @param  Builder<Rep>  $query
     * @return Builder<Rep>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function clock(): RepClock
    {
        return new RepClock($this->timezone);
    }

    /**
     * @return BelongsToMany<Meeting, $this, MeetingRep>
     */
    public function meetings(): BelongsToMany
    {
        return $this->belongsToMany(Meeting::class, 'meeting_rep')->using(MeetingRep::class)->withPivot('tenant_id');
    }

    /**
     * @return HasMany<RepWeekSync, $this>
     */
    public function weekSyncs(): HasMany
    {
        return $this->hasMany(RepWeekSync::class);
    }

    /**
     * @return HasMany<DigestDelivery, $this>
     */
    public function digestDeliveries(): HasMany
    {
        return $this->hasMany(DigestDelivery::class);
    }
}
