<?php

declare(strict_types=1);

namespace Tests\Integration\Commands;

use App\Support\JobLog;
use Closure;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Swaps the container's JobLog for one that writes into `$logRecords`, so a
 * test can assert both the events written and that no PII reached them.
 */
trait RecordsJobLog
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    protected array $logRecords = [];

    protected function recordJobLog(): void
    {
        $sink = function (string $level, string $message, array $context): void {
            $this->logRecords[] = ['level' => $level, 'message' => $message, 'context' => $context];
        };

        $this->app->instance(JobLog::class, new JobLog(new class($sink) extends AbstractLogger
        {
            /** @param Closure(string, string, array<string, mixed>): void $sink */
            public function __construct(private readonly Closure $sink) {}

            /** @param array<string, mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                ($this->sink)((string) $level, (string) $message, $context);
            }
        }));
    }

    /** @return list<string> */
    protected function loggedEvents(): array
    {
        return array_map(static fn (array $r): string => $r['message'], $this->logRecords);
    }

    protected function loggedText(): string
    {
        return (string) json_encode($this->logRecords);
    }
}
