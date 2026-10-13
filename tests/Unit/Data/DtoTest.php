<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\AttendeeData;
use App\Data\DigestData;
use App\Data\DigestLead;
use App\Data\DigestMeeting;
use App\Data\Enums\DeliveryStatus;
use App\Data\Enums\MeetingStatus;
use App\Data\Enums\ResponseStatus;
use App\Data\MeetingData;
use App\Data\ProviderCredentials;
use App\Data\WeekSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DtoTest extends TestCase
{
    #[Test]
    public function attendee_email_is_normalised(): void
    {
        $attendee = new AttendeeData('  Ana.Silva@Client.COM ', 'Ana Silva');

        self::assertSame('ana.silva@client.com', $attendee->email);
        self::assertSame('client.com', $attendee->domain());
        self::assertSame(ResponseStatus::Accepted, $attendee->responseStatus);
        self::assertNull($attendee->linkedinUrl);
    }

    #[Test]
    public function attendee_without_at_sign_has_empty_domain(): void
    {
        self::assertSame('', (new AttendeeData('nobody', 'Nobody'))->domain());
    }

    #[Test]
    public function week_schedule_normalises_rep_email_and_lists_external_ids(): void
    {
        $meeting = new MeetingData('evt_1', 'Intro', CarbonImmutable::parse('2026-10-14 10:00'), CarbonImmutable::parse('2026-10-14 10:30'), MeetingStatus::Confirmed, [
            new AttendeeData('a@client.com', 'A'),
        ]);
        $week = new WeekSchedule('RUI@Acme.test', CarbonImmutable::parse('2026-10-12'), [$meeting], CarbonImmutable::now());

        self::assertSame('rui@acme.test', $week->repEmail);
        self::assertSame(['evt_1'], $week->externalIds());
        self::assertSame(['a@client.com'], $meeting->attendeeEmails());
    }

    #[Test]
    public function provider_credentials_never_dump_their_values(): void
    {
        $credentials = new ProviderCredentials(['client_secret' => 's3cret']);

        self::assertSame('s3cret', $credentials->get('client_secret'));
        self::assertTrue($credentials->has('client_secret'));
        self::assertNull($credentials->get('missing'));
        self::assertSame('fallback', $credentials->get('missing', 'fallback'));
        self::assertFalse($credentials->isEmpty());
        self::assertTrue((new ProviderCredentials)->isEmpty());
        self::assertStringNotContainsString('s3cret', (string) $credentials);
        self::assertStringNotContainsString('s3cret', print_r($credentials, true));
    }

    #[Test]
    public function digest_data_reports_emptiness_and_lead_count(): void
    {
        $lead = new DigestLead('a@client.com', 'A', 'Client', null, 2);
        $meeting = new DigestMeeting('evt_1', 'Intro', CarbonImmutable::parse('2026-10-14 10:00'), CarbonImmutable::parse('2026-10-14 10:30'), [$lead, $lead]);

        $empty = new DigestData(1, CarbonImmutable::parse('2026-10-14'), 'UTC', []);
        $full = new DigestData(1, CarbonImmutable::parse('2026-10-14'), 'UTC', [$meeting]);

        self::assertTrue($empty->isEmpty());
        self::assertSame(0, $empty->leadCount());
        self::assertFalse($full->isEmpty());
        self::assertSame(2, $full->leadCount());
    }

    #[Test]
    public function enums_encode_the_business_rules(): void
    {
        self::assertTrue(MeetingStatus::Confirmed->isHeld());
        self::assertTrue(MeetingStatus::Tentative->isHeld());
        self::assertFalse(MeetingStatus::Cancelled->isHeld());
        self::assertSame(['confirmed', 'tentative'], MeetingStatus::heldValues());

        self::assertFalse(ResponseStatus::Declined->countsAsAttending());
        self::assertTrue(ResponseStatus::NeedsAction->countsAsAttending());

        self::assertFalse(DeliveryStatus::Pending->isTerminal());
        self::assertFalse(DeliveryStatus::Sending->isTerminal());
        self::assertTrue(DeliveryStatus::Sent->isTerminal());
        self::assertTrue(DeliveryStatus::FailedSend->isFailure());
        self::assertFalse(DeliveryStatus::SkippedEmpty->isFailure());
    }
}
