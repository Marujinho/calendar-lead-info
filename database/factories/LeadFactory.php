<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
final class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'full_name' => $this->faker->name(),
            'company_name' => $this->faker->company(),
            'linkedin_url' => 'https://www.linkedin.com/in/'.$this->faker->unique()->userName(),
        ];
    }

    public function withoutLinkedin(): self
    {
        return $this->state(fn (): array => ['linkedin_url' => null]);
    }
}
