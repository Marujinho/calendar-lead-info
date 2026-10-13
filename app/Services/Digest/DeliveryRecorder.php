<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Data\Enums\DeliveryStatus;
use App\Models\DigestDelivery;
use App\Models\Rep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `digest_deliveries` (PRD FR-12, §20 P10, ADR-0006).
 *
 * pending ─┬─> skipped_empty | failed_no_data
 *          └─> sending ─┬─> sent
 *                       └─> failed_send
 *
 * Invariants:
 *  - At most one row per rep and local date: `claim()` relies on the unique
 *    (rep_id, local_date) index, so of two concurrent claimers exactly one
 *    gets a row back.
 *  - Every transition is a conditional UPDATE on the current status, so a
 *    transition is applied at most once and terminal rows never change.
 *    Each method returns whether this call applied it.
 *  - A row in `sending` with no `failure_reason` may have been accepted by
 *    the mail transport; it is never moved back to a sendable state. A row in
 *    `sending` *with* a `failure_reason` is one whose transport call threw
 *    (the transport rejected it), so a retry may send again.
 */
final class DeliveryRecorder
{
    /** Longest failure reason the column holds. */
    private const REASON_MAX = 64;

    /**
     * Insert the `pending` row for `$rep` on `$localDate`. Returns null when
     * another process already holds that rep and date.
     *
     * @param  CarbonImmutable  $localDate  rep-local day (its date in the rep's zone is stored)
     * @param  CarbonImmutable  $scheduledFor  the UTC instant of the local send time
     */
    public function claim(Rep $rep, CarbonImmutable $localDate, CarbonImmutable $scheduledFor): ?DigestDelivery
    {
        try {
            return DigestDelivery::query()->create([
                'tenant_id' => $rep->tenant_id,
                'rep_id' => $rep->id,
                'local_date' => $localDate->setTimezone($rep->timezone)->toDateString(),
                'scheduled_for' => $scheduledFor->utc(),
                'status' => DeliveryStatus::Pending,
                'attempts' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * True when the job may (re)start the send path for this row: it is
     * `pending`, or `sending` after a transport call that is known to have
     * failed.
     */
    public function isSendable(DigestDelivery $delivery): bool
    {
        return $delivery->status === DeliveryStatus::Pending
            || ($delivery->status === DeliveryStatus::Sending && $delivery->failure_reason !== null);
    }

    /** Take the row into `sending` right before the transport call; counts one attempt. */
    public function markSending(DigestDelivery $delivery): bool
    {
        return $this->transition($delivery, $this->sendable(...), [
            'status' => DeliveryStatus::Sending->value,
            'failure_reason' => null,
            'attempts' => DB::raw('attempts + 1'),
        ]);
    }

    /**
     * The transport accepted the message.
     *
     * @param  CarbonImmutable|null  $dataRefreshedAt  the `last_success_at` the digest was built from
     */
    public function markSent(DigestDelivery $delivery, ?CarbonImmutable $dataRefreshedAt): bool
    {
        return $this->transition($delivery, $this->inStatus(DeliveryStatus::Sending), [
            'status' => DeliveryStatus::Sent->value,
            'failure_reason' => null,
            'sent_at' => CarbonImmutable::now()->utc(),
            'data_refreshed_at' => $dataRefreshedAt?->utc(),
        ]);
    }

    /** Nothing to send today (FR-13, ADR-0005). */
    public function markSkippedEmpty(DigestDelivery $delivery): bool
    {
        return $this->transition($delivery, $this->sendable(...), [
            'status' => DeliveryStatus::SkippedEmpty->value,
            'failure_reason' => null,
        ]);
    }

    /** No successful sync covers the digest's week (FR-11). */
    public function markFailedNoData(DigestDelivery $delivery): bool
    {
        return $this->transition($delivery, $this->sendable(...), [
            'status' => DeliveryStatus::FailedNoData->value,
            'failure_reason' => null,
        ]);
    }

    /**
     * The transport call threw. The row stays `sending` but now carries the
     * reason, which marks it as safe to retry within the send window.
     */
    public function markSendAttemptFailed(DigestDelivery $delivery, string $reason): bool
    {
        return $this->transition($delivery, $this->inStatus(DeliveryStatus::Sending), [
            'failure_reason' => $this->reason($reason),
        ]);
    }

    /** Final failure: retries exhausted, an uncertain crash, or the job could not be queued. */
    public function markFailedSend(DigestDelivery $delivery, string $reason): bool
    {
        return $this->transition(
            $delivery,
            static fn (Builder $q): Builder => $q->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Sending->value]),
            [
                'status' => DeliveryStatus::FailedSend->value,
                'failure_reason' => $this->reason($reason),
            ],
        );
    }

    /**
     * @param  Builder<DigestDelivery>  $query
     * @return Builder<DigestDelivery>
     */
    private function sendable(Builder $query): Builder
    {
        return $query->where(static function (Builder $q): void {
            $q->where('status', DeliveryStatus::Pending->value)
                ->orWhere(static function (Builder $q): void {
                    $q->where('status', DeliveryStatus::Sending->value)->whereNotNull('failure_reason');
                });
        });
    }

    /**
     * @return \Closure(Builder<DigestDelivery>): Builder<DigestDelivery>
     */
    private function inStatus(DeliveryStatus $status): \Closure
    {
        return static fn (Builder $q): Builder => $q->where('status', $status->value);
    }

    /**
     * Apply `$changes` only if the row still matches `$guard`, then reload it.
     *
     * @param  \Closure(Builder<DigestDelivery>): Builder<DigestDelivery>  $guard
     * @param  array<string, mixed>  $changes
     */
    private function transition(DigestDelivery $delivery, \Closure $guard, array $changes): bool
    {
        $query = $guard(DigestDelivery::query()->whereKey($delivery->getKey()));

        $applied = $query->update($changes) === 1;

        $delivery->refresh();

        return $applied;
    }

    private function reason(string $reason): string
    {
        return mb_substr($reason, 0, self::REASON_MAX);
    }
}
