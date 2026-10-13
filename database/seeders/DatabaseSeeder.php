<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Intentionally empty: tenants and reps are created through artisan commands
 * (tenant:create, rep:add, rep:import), never seeded.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void {}
}
