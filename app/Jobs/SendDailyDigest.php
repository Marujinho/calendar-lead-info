<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\DigestBuilder;
use App\Contracts\DigestMailer;
use App\Contracts\TenantScopedJob;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\DigestDelivery;
use App\Models\Rep;
use App\Models\Tenant;
use App\Services\Digest\DataFreshness;
use App\Services\Digest\DeliveryRecorder;
use App\Support\JobLog;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends one rep's digest for one local date, exactly once (PRD FR-11–FR-13,
 * G1, A09, A10; ADR-0005, ADR-0006).
 *
 * The `digest_deliveries` row already exists (`digest:dispatch-due` inserted
 * it); this job only moves it through the state machine in DeliveryRecorder.
 *
 * Invariants:
 *  - Never sends for a row that is terminal, or that is `sending` with no
 *    recorded transport failure: that row may already have been accepted by
 *    the transport, so it becomes `failed_send` / `uncertain` instead.
 *  - The row is `sending` before the transport is called.
 *  - Never calls a calendar provider; freshness and content come from MySQL.
 *  - The payload carries ids only (A08). Logs carry ids only (A09).
 *  - Transport failures are retried until send_hour + send_window_minutes
 *    local, then the row is `failed_send` and an alert is logged.
 */
final class SendDailyDigest implements ShouldBeUnique, ShouldQueue, TenantScopedJob
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds between retries of a failed transport call, inside the send window. */
    public int $backoff = 60;

    /** A send later than this after `scheduled_for` misses G1 and raises `digest.send_delayed`. */
    public const DELAY_ALERT_SECONDS = 300;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $deliveryId,
    ) {
        $this->onQueue(config()->string('queue.queues.digest'));
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    /** Hold the unique lock no longer than the send window it protects. */
    public function uniqueFor(): int
    {
        return config()->integer('digest.send_window_minutes') * 60;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [app(SetTenantContext::class)];
    }

    /**
     * Retries stop at the end of the rep's local send window (08:15 by
     * default). Evaluated when the job is queued and by the worker, possibly
     * outside any tenant context, so it sets the context itself.
     */
    public function retryUntil(): DateTimeInterface
    {
        $scheduledFor = $this->withTenant(
            fn (): ?CarbonImmutable => DigestDelivery::query()->find($this->deliveryId)?->scheduled_for,
        ) ?? CarbonImmutable::now();

        return $scheduledFor->addMinutes(config()->integer('digest.send_window_minutes'));
    }

    public function handle(
        DigestBuilder $builder,
        DigestMailer $mailer,
        DeliveryRecorder $recorder,
        DataFreshness $freshness,
        JobLog $log,
    ): void {
        $delivery = DigestDelivery::query()->with('rep')->find($this->deliveryId);

        if ($delivery === null || ! $delivery->rep instanceof Rep) {
            $log->warning('digest.delivery_missing', ['tenant_id' => $this->tenantId]);

            return;
        }

        $rep = $delivery->rep;
        $context = [
            'tenant_id' => $this->tenantId,
            'rep_id' => $rep->id,
            'local_date' => $delivery->local_date->toDateString(),
        ];

        if ($delivery->status->isTerminal()) {
            $log->info('digest.duplicate_skipped', $context + ['status' => $delivery->status->value]);

            return;
        }

        if (! $recorder->isSendable($delivery)) {
            // `sending` with no recorded transport failure: a previous attempt
            // died after the transport may have accepted the mail (ADR-0006).
            if ($recorder->markFailedSend($delivery, 'uncertain')) {
                $log->error('digest.failed_send', $context + ['reason' => 'uncertain', 'attempt' => $delivery->attempts]);
            }

            return;
        }

        $localDate = CarbonImmutable::parse($delivery->local_date->toDateString(), $rep->timezone);

        $data = $freshness->for($rep, $localDate);
        if (! $data->hasData()) {
            if ($recorder->markFailedNoData($delivery)) {
                $log->error('digest.failed_no_data', $context + ['status' => $delivery->status->value]);
            }

            return;
        }

        $digest = $builder->build($rep, $localDate);
        if ($digest->isEmpty()) {
            if ($recorder->markSkippedEmpty($delivery)) {
                $log->info('digest.skipped_empty', $context + ['status' => $delivery->status->value]);
            }

            return;
        }

        if (! $recorder->markSending($delivery)) {
            $log->info('digest.duplicate_skipped', $context + ['status' => $delivery->status->value]);

            return;
        }

        try {
            $mailer->send($rep, $digest, $data->noticeTimestamp());
        } catch (Throwable $e) {
            $recorder->markSendAttemptFailed($delivery, class_basename($e));
            $log->warning('digest.send_attempt_failed', $context + [
                'exception' => $e::class,
                'attempt' => $delivery->attempts,
            ]);

            throw $e;
        }

        $recorder->markSent($delivery, $data->refreshedAt);

        $delaySeconds = (int) $delivery->scheduled_for->diffInSeconds(CarbonImmutable::now(), false);
        $log->info('digest.sent', $context + [
            'status' => $data->label(),
            'lead_count' => $digest->leadCount(),
            'meeting_count' => count($digest->meetings),
            'delay_seconds' => $delaySeconds,
            'attempt' => $delivery->attempts,
        ]);

        if ($delaySeconds > self::DELAY_ALERT_SECONDS) {
            $log->error('digest.send_delayed', $context + ['delay_seconds' => $delaySeconds]);
        }
    }

    /**
     * Called by the worker once retries are exhausted (or on a non-retryable
     * error). Runs without job middleware, so it sets the tenant itself.
     * Keeps the transport's failure reason when one was recorded.
     */
    public function failed(Throwable $e): void
    {
        $this->withTenant(function () use ($e): void {
            $delivery = DigestDelivery::query()->find($this->deliveryId);
            if ($delivery === null) {
                return;
            }

            $reason = $delivery->failure_reason ?? class_basename($e);
            if (app(DeliveryRecorder::class)->markFailedSend($delivery, $reason)) {
                app(JobLog::class)->error('digest.failed_send', [
                    'tenant_id' => $this->tenantId,
                    'rep_id' => $delivery->rep_id,
                    'local_date' => $delivery->local_date->toDateString(),
                    'reason' => $delivery->failure_reason,
                    'exception' => $e::class,
                    'attempt' => $delivery->attempts,
                ]);
            }
        });
    }

    /**
     * Run `$callback` inside this job's tenant. Returns null when the tenant
     * no longer exists, without touching any tenant data.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T|null
     */
    private function withTenant(\Closure $callback): mixed
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return null;
        }

        return app(TenantContext::class)->run($tenant, static fn (): mixed => $callback());
    }
}
