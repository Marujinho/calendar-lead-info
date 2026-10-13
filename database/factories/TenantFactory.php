<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = $this->faker->unique()->domainWord();

        return [
            'name' => ucfirst($slug),
            'internal_domains' => [$slug.'.test'],
            'default_timezone' => 'UTC',
            'provider_credentials' => null,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function withCredentials(array $credentials): self
    {
        return $this->state(fn (): array => ['provider_credentials' => $credentials]);
    }
}
