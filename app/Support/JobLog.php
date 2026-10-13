<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * The only way jobs and commands write structured logs (PRD NFR-5, A09).
 *
 * Invariants:
 *  - Context keys must be in ALLOWED_KEYS. Anything else throws, so an email,
 *    name or URL can't be logged by accident.
 *  - Values are scalars or null. Strings must match VALUE_PATTERN (no "@", no
 *    spaces), which rejects emails and free text while allowing ids, dates,
 *    enum values and class names.
 *  - `error()` is the alert channel: Datadog monitors key on `event`.
 */
final class JobLog
{
    public const ALLOWED_KEYS = [
        'tenant_id', 'rep_id', 'lead_count', 'meeting_count', 'local_date', 'week_start',
        'status', 'outcome', 'reason', 'duration_ms', 'attempt', 'calls', 'cap',
        'delay_seconds', 'exception', 'queue',
    ];

    private const VALUE_PATTERN = '/^[A-Za-z0-9_.:\\\\\/-]*$/';

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->logger->info($event, $this->validated($event, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->logger->warning($event, $this->validated($event, $context));
    }

    /**
     * Alert-level entry. Every Datadog monitor in docs/operations.md keys on these.
     *
     * @param  array<string, mixed>  $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->logger->error($event, $this->validated($event, $context));
    }

    /**
     * Validates at runtime as well as by type, because callers may build the
     * context array from untyped data (config, payload counts, exceptions).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, scalar|null>
     */
    private function validated(string $event, array $context): array
    {
        $this->assertValue('event', $event);

        $clean = [];
        foreach ($context as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('JobLog: key "%s" is not allowed', $key));
            }

            if (is_string($value)) {
                $this->assertValue($key, $value);
            } elseif (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(sprintf('JobLog: value for "%s" must be a scalar or null', $key));
            }

            $clean[$key] = $value;
        }

        $clean['event'] = $event;

        return $clean;
    }

    private function assertValue(string $key, string $value): void
    {
        if (preg_match(self::VALUE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('JobLog: value for "%s" contains characters that are not allowed in logs', $key));
        }
    }
}
