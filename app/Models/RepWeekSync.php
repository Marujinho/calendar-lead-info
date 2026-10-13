<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\RepWeekSyncFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sync bookkeeping for one rep and one ISO week (identity: rep_id + week_start).
 *
 * `last_success_at` drives freshness (FR-11): the digest for local day D reads
 * the row for the week containing D. Null = no data; older than D 00:00 = stale.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $rep_id
 * @property CarbonImmutable $week_start
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_attempt_at
 * @property int $attempts
 * @property string|null $last_error_class
 */
final class RepWeekSync extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RepWeekSyncFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'rep_id', 'week_start', 'last_success_at', 'last_attempt_at', 'attempts', 'last_error_class'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_date',
            'last_success_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Rep, $this>
     */
    public function rep(): BelongsTo
    {
        return $this->belongsTo(Rep::class);
    }
}
