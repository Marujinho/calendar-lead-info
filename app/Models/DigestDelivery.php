<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Enums\DeliveryStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\DigestDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rep's digest for one local date. The unique (rep_id, local_date) index is
 * the exactly-once guarantee (FR-12): the dispatcher inserts this row first
 * and only the process whose insert succeeded dispatches the send job.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $rep_id
 * @property CarbonImmutable $local_date
 * @property CarbonImmutable $scheduled_for
 * @property DeliveryStatus $status
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $data_refreshed_at
 * @property int $attempts
 */
final class DigestDelivery extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DigestDeliveryFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'rep_id', 'local_date', 'scheduled_for', 'status', 'failure_reason', 'sent_at', 'data_refreshed_at', 'attempts'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_date' => 'immutable_date',
            'scheduled_for' => 'immutable_datetime',
            'status' => DeliveryStatus::class,
            'sent_at' => 'immutable_datetime',
            'data_refreshed_at' => 'immutable_datetime',
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
