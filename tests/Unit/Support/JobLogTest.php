<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\JobLog;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

final class JobLogTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    private JobLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $sink = function (string $level, string $message, array $context): void {
            $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
        };
        $this->log = new JobLog(new class($sink) extends AbstractLogger
        {
            /** @param \Closure(string, string, array<string, mixed>): void $sink */
            public function __construct(private readonly \Closure $sink) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->sink)((string) $level, (string) $message, $context);
            }
        });
    }

    #[Test]
    public function it_writes_the_event_key_and_allowed_context(): void
    {
        $this->log->info('sync.completed', ['tenant_id' => 1, 'rep_id' => 7, 'week_start' => '2026-10-12', 'duration_ms' => 312]);

        self::assertCount(1, $this->records);
        self::assertSame('info', $this->records[0]['level']);
        self::assertSame('sync.completed', $this->records[0]['message']);
        self::assertSame(
            ['tenant_id' => 1, 'rep_id' => 7, 'week_start' => '2026-10-12', 'duration_ms' => 312, 'event' => 'sync.completed'],
            $this->records[0]['context'],
        );
    }

    #[Test]
    public function error_is_the_alert_level(): void
    {
        $this->log->error('digest.failed_no_data', ['tenant_id' => 1, 'rep_id' => 2, 'local_date' => '2026-10-14']);

        self::assertSame('error', $this->records[0]['level']);
    }

    #[Test]
    public function it_rejects_keys_outside_the_allowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('key "email" is not allowed');

        $this->log->info('sync.completed', ['email' => 'x']);
    }

    #[Test]
    public function it_rejects_string_values_that_look_like_emails_or_free_text(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->log->info('sync.failed', ['reason' => 'lead ana@client.com not found']);
    }

    #[Test]
    public function it_rejects_non_scalar_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->log->info('sync.failed', ['reason' => ['nested']]);
    }

    #[Test]
    public function it_accepts_enum_values_class_names_and_dates(): void
    {
        $this->log->warning('provider.call', [
            'outcome' => 'rejected_cap',
            'exception' => 'App\\Exceptions\\ProviderTimeoutException',
            'local_date' => '2026-10-14',
            'attempt' => 2,
            'reason' => null,
        ]);

        self::assertSame('warning', $this->records[0]['level']);
    }
}
