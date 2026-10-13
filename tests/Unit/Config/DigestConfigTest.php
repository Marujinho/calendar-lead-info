<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class DigestConfigTest extends TestCase
{
    public function test_schedule_defaults_match_the_prd(): void
    {
        $this->assertSame(8, config('digest.send_hour'));
        $this->assertSame(15, config('digest.send_window_minutes'));
        $this->assertSame(5, config('digest.sync_hour'));
        $this->assertSame('07:45', config('digest.sync_cutoff'));
        $this->assertSame(35, config('digest.retention_days'));
    }
}
