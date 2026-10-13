<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Enums\CallOutcome;
use App\Data\Enums\CallPurpose;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProviderCallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row for every attempted provider call (G2 metric, A06 cap). Written by
 * the MeteredCalendarProvider decorator only.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $rep_id
 * @property CarbonImmutable $week_start
 * @property CallPurpose $purpose
 * @property CallOutcome $outcome
 * @property int $duration_ms
 * @property CarbonImmutable $called_at
 */
final class ProviderCall extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProviderCallFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'rep_id', 'week_start', 'purpose', 'outcome', 'duration_ms', 'called_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_date',
            'purpose' => CallPurpose::class,
            'outcome' => CallOutcome::class,
            'duration_ms' => 'integer',
            'called_at' => 'immutable_datetime',
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
