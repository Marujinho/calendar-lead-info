<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class QueueConfigTest extends TestCase
{
    public function test_the_three_application_queues_are_configured(): void
    {
        $this->assertSame(
            ['sync' => 'sync', 'digest' => 'digest', 'maintenance' => 'maintenance'],
            config('queue.queues'),
        );
    }

    public function test_queue_names_are_distinct_so_each_worker_drains_its_own_queue(): void
    {
        /** @var array<string, string> $queues */
        $queues = config('queue.queues');

        $this->assertCount(3, array_unique(array_values($queues)));
    }
}
