<?php

declare(strict_types=1);

namespace Tests\Integration\Digest;

use App\Data\Enums\DeliveryStatus;
use App\Models\DigestDelivery;
use App\Models\Rep;
use App\Models\Tenant;
use App\Services\Digest\DeliveryRecorder;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * PRD FR-10, FR-12, §20 P10: the delivery row is the exactly-once guarantee,
 * and every state change is applied at most once.
 */
final class RecorderTest extends IntegrationTestCase
{
    private Tenant $tenant;

    private DeliveryRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->actingAsTenant();
        $this->recorder = new DeliveryRecorder;
        $this->freezeAt('2026-10-14 08:01', 'Europe/Lisbon');
    }

    #[Test]
    public function claim_inserts_a_pending_row(): void
    {
        $rep = $this->rep('Europe/Lisbon');

        $delivery = $this->claimToday($rep);

        self::assertNotNull($delivery);
        self::assertSame(DeliveryStatus::Pending, $delivery->status);
        self::assertSame($this->tenant->id, $delivery->tenant_id);
        self::assertSame('2026-10-14', $delivery->local_date->toDateString());
        self::assertSame(0, $delivery->attempts);
    }

    #[Test]
    public function a_second_claim_for_the_same_rep_and_date_returns_null_and_adds_no_row(): void
    {
        $rep = $this->rep('Europe/Lisbon');

        self::assertNotNull($this->claimToday($rep));
        self::assertNull($this->claimToday($rep));
        self::assertSame(1, DigestDelivery::query()->where('rep_id', $rep->id)->count());
    }

    #[Test]
    public function a_row_inserted_by_a_concurrent_dispatcher_wins(): void
    {
        $rep = $this->rep('Europe/Lisbon');
        $theirs = DigestDelivery::factory()->for($rep)->create(['local_date' => '2026-10-14']);

        self::assertNull($this->claimToday($rep));
        self::assertSame([$theirs->id], DigestDelivery::query()->pluck('id')->all());
    }

    #[Test]
    public function the_same_local_date_for_another_rep_is_its_own_row(): void
    {
        self::assertNotNull($this->claimToday($this->rep('Europe/Lisbon')));
        self::assertNotNull($this->claimToday($this->rep('Europe/Lisbon')));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sendInstants(): array
    {
        return [
            'Lisbon summer time' => ['Europe/Lisbon', '2026-10-14', '2026-10-14 07:00:00'],
            'Sao Paulo (no DST in 2026)' => ['America/Sao_Paulo', '2026-10-14', '2026-10-14 11:00:00'],
            'Lisbon day before fall-back' => ['Europe/Lisbon', '2026-10-24', '2026-10-24 07:00:00'],
            'Lisbon fall-back day' => ['Europe/Lisbon', '2026-10-25', '2026-10-25 08:00:00'],
            'Lisbon spring-forward day' => ['Europe/Lisbon', '2026-03-29', '2026-03-29 07:00:00'],
            'New York day before fall-back' => ['America/New_York', '2026-10-31', '2026-10-31 12:00:00'],
            'New York fall-back day' => ['America/New_York', '2026-11-01', '2026-11-01 13:00:00'],
            'New York spring-forward day' => ['America/New_York', '2026-03-08', '2026-03-08 12:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('sendInstants')]
    public function scheduled_for_is_0800_local_stored_as_utc(string $zone, string $localDate, string $expectedUtc): void
    {
        $rep = $this->rep($zone);
        $day = CarbonImmutable::parse($localDate, $zone);

        $delivery = $this->recorder->claim($rep, $day, $rep->clock()->atLocalTime($day, '08:00'));

        self::assertNotNull($delivery);
        $stored = DigestDelivery::query()->findOrFail($delivery->id);
        self::assertSame($localDate, $stored->local_date->toDateString());
        self::assertSame($expectedUtc, $stored->scheduled_for->utc()->toDateTimeString());
    }

    #[Test]
    public function the_happy_path_goes_pending_sending_sent(): void
    {
        $delivery = $this->pending();
        $refreshedAt = CarbonImmutable::parse('2026-10-14 04:00', 'UTC');

        self::assertTrue($this->recorder->markSending($delivery));
        self::assertSame(DeliveryStatus::Sending, $delivery->status);
        self::assertSame(1, $delivery->attempts);

        self::assertTrue($this->recorder->markSent($delivery, $refreshedAt));
        self::assertSame(DeliveryStatus::Sent, $delivery->status);
        self::assertSame('2026-10-14 07:01:00', $delivery->sent_at?->utc()->toDateTimeString());
        self::assertSame('2026-10-14 04:00:00', $delivery->data_refreshed_at?->utc()->toDateTimeString());
        self::assertNull($delivery->failure_reason);
    }

    #[Test]
    public function sent_is_terminal(): void
    {
        $delivery = $this->pending();
        $this->recorder->markSending($delivery);
        $this->recorder->markSent($delivery, null);

        self::assertFalse($this->recorder->markSent($delivery, null));
        self::assertFalse($this->recorder->markSending($delivery));
        self::assertFalse($this->recorder->markFailedSend($delivery, 'late'));
        self::assertFalse($this->recorder->markSkippedEmpty($delivery));
        self::assertFalse($this->recorder->markFailedNoData($delivery));
        self::assertSame(DeliveryStatus::Sent, $delivery->status);
        self::assertSame(1, $delivery->attempts);
    }

    #[Test]
    public function pending_can_be_skipped_empty_once(): void
    {
        $delivery = $this->pending();

        self::assertTrue($this->recorder->markSkippedEmpty($delivery));
        self::assertSame(DeliveryStatus::SkippedEmpty, $delivery->status);
        self::assertFalse($this->recorder->markSkippedEmpty($delivery));
        self::assertFalse($this->recorder->markSending($delivery));
    }

    #[Test]
    public function pending_can_fail_for_no_data_once(): void
    {
        $delivery = $this->pending();

        self::assertTrue($this->recorder->markFailedNoData($delivery));
        self::assertSame(DeliveryStatus::FailedNoData, $delivery->status);
        self::assertFalse($this->recorder->markFailedNoData($delivery));
        self::assertFalse($this->recorder->markFailedSend($delivery, 'x'));
    }

    #[Test]
    public function sending_without_a_recorded_failure_is_never_sendable_again(): void
    {
        $delivery = $this->pending();
        $this->recorder->markSending($delivery);

        self::assertFalse($this->recorder->isSendable($delivery));
        self::assertFalse($this->recorder->markSending($delivery));
        self::assertFalse($this->recorder->markSkippedEmpty($delivery));
        self::assertSame(1, $delivery->attempts);
    }

    #[Test]
    public function a_recorded_transport_failure_makes_sending_retryable(): void
    {
        $delivery = $this->pending();
        $this->recorder->markSending($delivery);

        self::assertTrue($this->recorder->markSendAttemptFailed($delivery, 'TransportException'));
        self::assertSame(DeliveryStatus::Sending, $delivery->status);
        self::assertSame('TransportException', $delivery->failure_reason);
        self::assertTrue($this->recorder->isSendable($delivery));

        self::assertTrue($this->recorder->markSending($delivery));
        self::assertSame(2, $delivery->attempts);
        self::assertNull($delivery->failure_reason, 'a new attempt clears the reason, so a crash during it reads as uncertain');
    }

    #[Test]
    public function a_send_attempt_failure_is_only_recorded_while_sending(): void
    {
        $delivery = $this->pending();

        self::assertFalse($this->recorder->markSendAttemptFailed($delivery, 'TransportException'));
        self::assertNull($delivery->failure_reason);
    }

    #[Test]
    public function failed_send_applies_from_pending_or_sending_and_truncates_the_reason(): void
    {
        $fromPending = $this->pending();
        self::assertTrue($this->recorder->markFailedSend($fromPending, 'dispatch_failed'));
        self::assertSame(DeliveryStatus::FailedSend, $fromPending->status);
        self::assertSame('dispatch_failed', $fromPending->failure_reason);

        $fromSending = $this->pending();
        $this->recorder->markSending($fromSending);
        self::assertTrue($this->recorder->markFailedSend($fromSending, str_repeat('x', 80)));
        self::assertSame(64, mb_strlen((string) $fromSending->failure_reason));
        self::assertFalse($this->recorder->markFailedSend($fromSending, 'again'));
    }

    #[Test]
    public function another_tenants_row_cannot_be_moved(): void
    {
        $other = Tenant::factory()->create();
        $foreign = $this->app->make(TenantContext::class)->run($other, function () use ($other): DigestDelivery {
            $rep = Rep::factory()->for($other)->create();

            return DigestDelivery::factory()->for($rep)->create();
        });

        // The update is tenant-scoped, so it matches nothing in this tenant.
        self::assertFalse($this->recorder->markSending($foreign));
        self::assertSame(DeliveryStatus::Pending, $foreign->status);
        self::assertSame(0, $foreign->attempts);
    }

    private function rep(string $zone): Rep
    {
        return Rep::factory()->for($this->tenant)->inTimezone($zone)->create();
    }

    private function claimToday(Rep $rep): ?DigestDelivery
    {
        $today = $rep->clock()->today();

        return $this->recorder->claim($rep, $today, $rep->clock()->atLocalTime($today, '08:00'));
    }

    private function pending(): DigestDelivery
    {
        $delivery = $this->claimToday($this->rep('Europe/Lisbon'));
        self::assertNotNull($delivery);

        return $delivery;
    }
}
