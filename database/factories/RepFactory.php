<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Rep;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rep>
 */
final class RepFactory extends Factory
{
    protected $model = Rep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'name' => $this->faker->name(),
            'timezone' => 'UTC',
            'active' => true,
            'backfilled_at' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    public function inTimezone(string $timezone): self
    {
        return $this->state(fn (): array => ['timezone' => $timezone]);
    }
}
