<?php

declare(strict_types=1);

namespace App\Services\Digest;

use Carbon\CarbonImmutable;

/**
 * How current a rep's stored meeting data is for one digest date (PRD FR-11).
 *
 * Three states: no data (nothing may be sent), stale (send with the
 * "last refreshed at" notice) and fresh (send without a notice).
 *
 * Invariant: a stale or fresh value always carries the `last_success_at` it
 * was derived from; a no-data value never does. Construct only through the
 * named constructors so the two can never disagree.
 */
final readonly class Freshness
{
    private function __construct(
        public ?CarbonImmutable $refreshedAt,
        private bool $stale,
    ) {}

    public static function noData(): self
    {
        return new self(null, false);
    }

    public static function stale(CarbonImmutable $refreshedAt): self
    {
        return new self($refreshedAt, true);
    }

    public static function fresh(CarbonImmutable $refreshedAt): self
    {
        return new self($refreshedAt, false);
    }

    public function hasData(): bool
    {
        return $this->refreshedAt !== null;
    }

    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * What the mailer receives: the refresh instant when the email must carry
     * the stale notice, null otherwise (DigestMailer contract).
     */
    public function noticeTimestamp(): ?CarbonImmutable
    {
        return $this->stale ? $this->refreshedAt : null;
    }

    /** `fresh`, `stale` or `no_data`, for logs. */
    public function label(): string
    {
        return match (true) {
            ! $this->hasData() => 'no_data',
            $this->stale => 'stale',
            default => 'fresh',
        };
    }
}
