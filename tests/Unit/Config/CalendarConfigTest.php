<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class CalendarConfigTest extends TestCase
{
    public function test_provider_defaults_to_fake(): void
    {
        $this->assertSame('fake', config('calendar.provider'));
    }

    public function test_fake_provider_is_not_allowed_by_default(): void
    {
        $this->assertFalse(config('calendar.allow_fake'));
    }

    public function test_numeric_defaults_match_the_prd(): void
    {
        $this->assertSame(10, config('calendar.timeout'));
        $this->assertSame(3, config('calendar.cap_multiplier'));
        $this->assertSame('ok', config('calendar.fake.mode'));
        $this->assertSame(0, config('calendar.fake.latency_ms'));
    }
}
