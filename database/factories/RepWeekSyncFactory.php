<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Rep;
use App\Models\RepWeekSync;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepWeekSync>
 */
final class RepWeekSyncFactory extends Factory
{
    protected $model = RepWeekSync::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rep_id' => Rep::factory(),
            'tenant_id' => fn (array $attributes): int => Rep::query()->findOrFail($attributes['rep_id'])->tenant_id,
            'week_start' => CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'last_success_at' => CarbonImmutable::now(),
            'last_attempt_at' => CarbonImmutable::now(),
            'attempts' => 1,
            'last_error_class' => null,
        ];
    }

    public function neverSucceeded(): self
    {
        return $this->state(fn (): array => ['last_success_at' => null]);
    }
}
