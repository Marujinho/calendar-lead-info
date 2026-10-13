<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meeting>
 */
final class MeetingFactory extends Factory
{
    protected $model = Meeting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfHour();

        return [
            'tenant_id' => Tenant::factory(),
            'external_id' => 'evt_'.$this->faker->unique()->uuid(),
            'title' => $this->faker->sentence(3),
            'start_at' => $start,
            'end_at' => $start->addMinutes(30),
            'status' => MeetingStatus::Confirmed,
            'removed_at' => null,
        ];
    }

    public function startingAt(CarbonImmutable $start, int $minutes = 30): self
    {
        return $this->state(fn (): array => ['start_at' => $start, 'end_at' => $start->addMinutes($minutes)]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => ['status' => MeetingStatus::Cancelled]);
    }

    public function tentative(): self
    {
        return $this->state(fn (): array => ['status' => MeetingStatus::Tentative]);
    }

    public function removed(): self
    {
        return $this->state(fn (): array => ['removed_at' => CarbonImmutable::now()]);
    }
}
