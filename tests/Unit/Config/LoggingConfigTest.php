<?php

namespace Tests\Unit\Config;

use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Tests\TestCase;

class LoggingConfigTest extends TestCase
{
    public function test_default_channel_is_stdout(): void
    {
        $this->assertSame('stdout', config('logging.default'));
    }

    public function test_stdout_channel_writes_to_stdout_with_json_formatter(): void
    {
        $this->assertSame('php://stdout', config('logging.channels.stdout.handler_with.stream'));
        $this->assertSame(JsonFormatter::class, config('logging.channels.stdout.formatter'));
    }

    public function test_default_channel_emits_one_decodable_json_record_per_line(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        /** @var array<string, mixed> $channel */
        $channel = config('logging.channels.'.config('logging.default'));
        $channel['handler_with'] = ['stream' => $stream];

        Log::build($channel)->info('digest sent for {rep_id}', ['rep_id' => 42]);

        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        $lines = array_values(array_filter(explode("\n", $contents)));
        $this->assertCount(1, $lines);

        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($record);
        $this->assertSame('digest sent for 42', $record['message']);
        $this->assertSame('INFO', $record['level_name']);
        $this->assertSame(['rep_id' => 42], $record['context']);
    }

    public function test_single_channel_remains_available_for_local_use(): void
    {
        $this->assertSame('single', config('logging.channels.single.driver'));
    }
}
