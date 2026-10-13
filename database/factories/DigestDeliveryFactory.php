<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Enums\DeliveryStatus;
use App\Models\DigestDelivery;
use App\Models\Rep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DigestDelivery>
 */
final class DigestDeliveryFactory extends Factory
{
    protected $model = DigestDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        return [
            'rep_id' => Rep::factory(),
            'tenant_id' => fn (array $attributes): int => Rep::query()->findOrFail($attributes['rep_id'])->tenant_id,
            'local_date' => $today->toDateString(),
            'scheduled_for' => $today->setTime(8, 0),
            'status' => DeliveryStatus::Pending,
            'failure_reason' => null,
            'sent_at' => null,
            'data_refreshed_at' => null,
            'attempts' => 0,
        ];
    }
}
