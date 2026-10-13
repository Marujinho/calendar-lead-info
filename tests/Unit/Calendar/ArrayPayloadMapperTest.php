<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use App\Data\Enums\MeetingStatus;
use App\Data\Enums\ResponseStatus;
use App\Exceptions\InvalidProviderPayloadException;
use App\Services\Calendar\ArrayPayloadMapper;
use App\Services\Calendar\MappedWeek;
use App\Support\JobLog;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

final class ArrayPayloadMapperTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    private ArrayPayloadMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $sink = function (string $level, string $message, array $context): void {
            $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
        };
        $this->mapper = new ArrayPayloadMapper(new JobLog(new class($sink) extends AbstractLogger
        {
            /** @param \Closure(string, string, array<string, mixed>): void $sink */
            public function __construct(private readonly \Closure $sink) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->sink)((string) $level, (string) $message, $context);
            }
        }));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function item(string $id = 'evt-1', array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'summary' => 'Intro call',
            'start' => '2026-10-14T14:00:00-03:00',
            'end' => '2026-10-14T14:30:00-03:00',
            'status' => 'confirmed',
            'attendees' => [[
                'email' => ' Maria.Silva@Globex.test ',
                'displayName' => 'Maria Silva',
                'company' => 'Globex',
                'linkedin' => 'https://www.linkedin.com/in/maria',
                'responseStatus' => 'accepted',
            ]],
        ], $overrides);
    }

    /**
     * @param  list<mixed>  $items
     */
    private function map(array $items): MappedWeek
    {
        return $this->mapper->map(['items' => $items], 'Ana@Acme.test', CarbonImmutable::parse('2026-10-12'));
    }

    #[Test]
    public function it_maps_a_valid_meeting_into_dtos_with_utc_times(): void
    {
        $mapped = $this->map([self::item()]);

        self::assertSame([], $mapped->rejected);
        self::assertSame('ana@acme.test', $mapped->schedule->repEmail);
        self::assertCount(1, $mapped->schedule->meetings);

        $meeting = $mapped->schedule->meetings[0];
        self::assertSame('evt-1', $meeting->externalId);
        self::assertSame('Intro call', $meeting->title);
        self::assertSame('2026-10-14T17:00:00+00:00', $meeting->startAt->toIso8601String());
        self::assertSame('2026-10-14T17:30:00+00:00', $meeting->endAt->toIso8601String());
        self::assertSame(MeetingStatus::Confirmed, $meeting->status);

        $attendee = $meeting->attendees[0];
        self::assertSame('maria.silva@globex.test', $attendee->email);
        self::assertSame('Maria Silva', $attendee->fullName);
        self::assertSame('Globex', $attendee->companyName);
        self::assertSame('https://www.linkedin.com/in/maria', $attendee->linkedinUrl);
        self::assertSame(ResponseStatus::Accepted, $attendee->responseStatus);
    }

    #[Test]
    public function it_applies_defaults_for_optional_fields(): void
    {
        $item = self::item();
        unset($item['status'], $item['summary']);
        $item['attendees'] = [['email' => 'tom@initech.test']];

        $meeting = $this->map([$item])->schedule->meetings[0];

        self::assertSame(MeetingStatus::Confirmed, $meeting->status);
        self::assertSame(ArrayPayloadMapper::NO_TITLE, $meeting->title);
        self::assertSame('tom', $meeting->attendees[0]->fullName);
        self::assertNull($meeting->attendees[0]->companyName);
        self::assertNull($meeting->attendees[0]->linkedinUrl);
        self::assertSame(ResponseStatus::Accepted, $meeting->attendees[0]->responseStatus);
    }

    #[Test]
    public function an_empty_summary_becomes_no_title_and_missing_attendees_is_an_empty_list(): void
    {
        $item = self::item(overrides: ['summary' => '  ']);
        unset($item['attendees']);

        $meeting = $this->map([$item])->schedule->meetings[0];

        self::assertSame(ArrayPayloadMapper::NO_TITLE, $meeting->title);
        self::assertSame([], $meeting->attendees);
    }

    #[Test]
    public function empty_linkedin_and_company_strings_become_null(): void
    {
        $item = self::item();
        $item['attendees'] = [['email' => 'a@b.test', 'company' => '', 'linkedin' => ' ']];

        $attendee = $this->map([$item])->schedule->meetings[0]->attendees[0];

        self::assertNull($attendee->companyName);
        self::assertNull($attendee->linkedinUrl);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string, 3: ?string, 4: ?string}>
     */
    public static function invalidMeetings(): iterable
    {
        $noId = self::item();
        unset($noId['id']);
        $noStart = self::item('bad');
        unset($noStart['start']);

        // [meeting, field, reason, expected externalId, offending value that must not leak]
        yield 'missing id' => [$noId, 'id', 'missing', null, null];
        yield 'empty id' => [self::item('  '), 'id', 'missing', null, null];
        yield 'missing start' => [$noStart, 'start', 'missing', 'bad', null];
        yield 'unparseable start' => [self::item('bad', ['start' => 'tomorrow 9am']), 'start', 'invalid', 'bad', 'tomorrow 9am'];
        yield 'start without offset' => [self::item('bad', ['start' => '2026-10-14T14:00:00']), 'start', 'invalid', 'bad', null];
        yield 'impossible date' => [self::item('bad', ['start' => '2026-02-30T25:00:00Z']), 'start', 'invalid', 'bad', null];
        yield 'bad end' => [self::item('bad', ['end' => 'soon']), 'end', 'invalid', 'bad', 'soon'];
        yield 'end equals start' => [self::item('bad', ['end' => '2026-10-14T14:00:00-03:00']), 'end', 'not_after_start', 'bad', null];
        yield 'end before start' => [self::item('bad', ['end' => '2026-10-14T13:00:00-03:00']), 'end', 'not_after_start', 'bad', null];
        yield 'unknown status' => [self::item('bad', ['status' => 'postponed']), 'status', 'unknown', 'bad', 'postponed'];
        yield 'non-string summary' => [self::item('bad', ['summary' => ['x']]), 'summary', 'invalid', 'bad', null];
        yield 'attendees not a list' => [self::item('bad', ['attendees' => 'x']), 'attendees', 'invalid', 'bad', null];
        yield 'attendee not an object' => [self::item('bad', ['attendees' => ['x']]), 'attendees', 'invalid', 'bad', null];
        yield 'bad email' => [self::item('bad', ['attendees' => [['email' => 'leaky.value-at-nowhere']]]), 'attendees.email', 'invalid', 'bad', 'leaky.value-at-nowhere'];
        yield 'missing email' => [self::item('bad', ['attendees' => [['displayName' => 'X']]]), 'attendees.email', 'invalid', 'bad', null];
        yield 'unknown responseStatus' => [self::item('bad', ['attendees' => [['email' => 'a@b.test', 'responseStatus' => 'maybe-later']]]), 'attendees.responseStatus', 'unknown', 'bad', 'maybe-later'];
        yield 'non-string displayName' => [self::item('bad', ['attendees' => [['email' => 'a@b.test', 'displayName' => 5]]]), 'attendees.displayName', 'invalid', 'bad', null];
        yield 'non-string linkedin' => [self::item('bad', ['attendees' => [['email' => 'a@b.test', 'linkedin' => ['url']]]]), 'attendees.linkedin', 'invalid', 'bad', null];
        yield 'non-string company' => [self::item('bad', ['attendees' => [['email' => 'a@b.test', 'company' => 1]]]), 'attendees.company', 'invalid', 'bad', null];
    }

    /**
     * @param  array<string, mixed>  $invalid
     */
    #[Test]
    #[DataProvider('invalidMeetings')]
    public function an_invalid_meeting_is_rejected_and_the_others_survive(array $invalid, string $field, string $reason, ?string $externalId, ?string $leak): void
    {
        $mapped = $this->map([self::item('good-1'), $invalid, self::item('good-2')]);

        self::assertSame(['good-1', 'good-2'], $mapped->schedule->externalIds());
        self::assertSame(1, $mapped->rejectedCount());

        $e = $mapped->rejected[0];
        self::assertInstanceOf(InvalidProviderPayloadException::class, $e);
        self::assertSame($field, $e->field);
        self::assertSame($reason, $e->reason);
        self::assertSame($externalId, $e->externalId);
        if ($leak !== null) {
            self::assertStringNotContainsString($leak, $e->getMessage());
            self::assertStringNotContainsString($leak, (string) json_encode($this->records));
        }
    }

    #[Test]
    public function a_non_array_item_is_rejected_individually(): void
    {
        $mapped = $this->map(['garbage', self::item()]);

        self::assertSame(['evt-1'], $mapped->schedule->externalIds());
        self::assertSame('items', $mapped->rejected[0]->field);
        self::assertSame('not_object', $mapped->rejected[0]->reason);
    }

    #[Test]
    public function each_rejection_is_logged_as_a_reason_code_only(): void
    {
        $this->map([self::item('bad', ['attendees' => [['email' => 'oops']]])]);

        self::assertCount(1, $this->records);
        self::assertSame('warning', $this->records[0]['level']);
        self::assertSame('calendar.payload.meeting_rejected', $this->records[0]['message']);
        self::assertSame(['reason' => 'attendees.email:invalid', 'event' => 'calendar.payload.meeting_rejected'], $this->records[0]['context']);
    }

    /**
     * @return iterable<string, array{0: array<mixed>}>
     */
    public static function unusablePayloads(): iterable
    {
        yield 'no items key' => [['kind' => 'calendar#events']];
        yield 'items not an array' => [['items' => 'x']];
        yield 'items is a map' => [['items' => ['a' => self::item()]]];
    }

    /**
     * @param  array<mixed>  $payload
     */
    #[Test]
    #[DataProvider('unusablePayloads')]
    public function a_payload_without_an_items_list_is_rejected_whole(array $payload): void
    {
        try {
            $this->mapper->map($payload, 'ana@acme.test', CarbonImmutable::parse('2026-10-12'));
            self::fail('Expected InvalidProviderPayloadException');
        } catch (InvalidProviderPayloadException $e) {
            self::assertSame('items', $e->field);
            self::assertSame('missing', $e->reason);
            self::assertNull($e->externalId);
        }
    }

    #[Test]
    public function an_empty_items_list_is_a_valid_empty_week(): void
    {
        $mapped = $this->map([]);

        self::assertSame([], $mapped->schedule->meetings);
        self::assertSame([], $mapped->rejected);
    }
}
