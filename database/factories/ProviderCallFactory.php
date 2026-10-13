<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Enums\CallOutcome;
use App\Data\Enums\CallPurpose;
use App\Models\ProviderCall;
use App\Models\Rep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCall>
 */
final class ProviderCallFactory extends Factory
{
    protected $model = ProviderCall::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rep_id' => Rep::factory(),
            'tenant_id' => fn (array $attributes): int => Rep::query()->findOrFail($attributes['rep_id'])->tenant_id,
            'week_start' => CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'purpose' => CallPurpose::Sync,
            'outcome' => CallOutcome::Success,
            'duration_ms' => 120,
            'called_at' => CarbonImmutable::now(),
        ];
    }
}
