<?php

declare(strict_types=1);

namespace App\Services\Calendar\Fake;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Builds the raw, JSON-shaped week payload the FakeCalendarProvider returns.
 *
 * Demo reps (listed in `database/fake_calendar/scenario.php`) get the
 * hand-written weekly template; any other rep email gets a procedurally
 * generated week from a PRNG seeded with (tenant seed, rep email, week).
 *
 * Invariants:
 *  - Pure: the same (seed, rep email, week Monday) always yields the same
 *    payload. No clock, no global PRNG state (a local Mt19937 engine is used).
 *  - A meeting on several reps' calendars carries the same external id and the
 *    same leads, times and status on each copy.
 *  - The scenario file is validated on load; a malformed file fails loudly.
 */
final class FakeScenario
{
    /** @var array<string, string> */
    private array $reps;

    /** @var array<string, array{email: string, name: string, company: ?string, linkedin: ?string}> */
    private array $people;

    /** @var list<array{key: string, summary: string, day: int, time: string, minutes: int, status: string, reps: list<string>, guests: array<string, string>}> */
    private array $meetings;

    /** @var array{first_names: list<string>, last_names: list<string>, companies: list<string>, pool_size: int, shared_slots: int} */
    private array $procedural;

    /** @var list<array{email: string, name: string, company: string, linkedin: ?string}>|null */
    private ?array $pool = null;

    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__, 4).'/database/fake_calendar/scenario.php';
        $data = require $path;
        if (! is_array($data)) {
            throw $this->invalid('root');
        }

        $this->reps = $this->stringMap($data['reps'] ?? null, 'reps');
        $this->people = $this->parsePeople($data['people'] ?? null);
        $this->meetings = $this->parseMeetings($data['meetings'] ?? null);
        $this->procedural = $this->parseProcedural($data['procedural'] ?? null);
    }

    public function isDemoRep(string $repEmail): bool
    {
        return array_key_exists($this->normalise($repEmail), $this->reps);
    }

    /**
     * @return list<string>
     */
    public function demoRepEmails(): array
    {
        return array_keys($this->reps);
    }

    /**
     * Raw provider payload for one rep and the ISO week starting on `$monday`
     * (only the calendar date of `$monday` is used; times are UTC).
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function weekPayload(string $seed, string $repEmail, CarbonImmutable $monday): array
    {
        $repEmail = $this->normalise($repEmail);
        $mondayUtc = CarbonImmutable::parse($monday->toDateString(), 'UTC');

        return ['items' => $this->isDemoRep($repEmail)
            ? $this->demoItems($repEmail, $mondayUtc)
            : $this->proceduralItems($seed, $repEmail, $mondayUtc)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function demoItems(string $repEmail, CarbonImmutable $mondayUtc): array
    {
        $items = [];
        foreach ($this->meetings as $m) {
            if (! in_array($repEmail, $m['reps'], true)) {
                continue;
            }

            [$hour, $minute] = array_map('intval', explode(':', $m['time']));
            $start = $mondayUtc->addDays($m['day'])->setTime($hour, $minute);

            $attendees = [];
            foreach ($m['reps'] as $rep) {
                $attendees[] = ['email' => $rep, 'displayName' => $this->reps[$rep], 'company' => null, 'linkedin' => null, 'responseStatus' => 'accepted'];
            }
            foreach ($m['guests'] as $key => $response) {
                $p = $this->people[$key];
                $attendees[] = ['email' => $p['email'], 'displayName' => $p['name'], 'company' => $p['company'], 'linkedin' => $p['linkedin'], 'responseStatus' => $response];
            }

            $items[] = $this->item('demo-'.$m['key'].'-'.$mondayUtc->format('Ymd'), $m['summary'], $start, $m['minutes'], $m['status'], $attendees);
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function proceduralItems(string $seed, string $repEmail, CarbonImmutable $mondayUtc): array
    {
        $week = $mondayUtc->format('Ymd');
        $rng = $this->rng($seed.'|'.$repEmail.'|'.$week);
        $self = ['email' => $repEmail, 'displayName' => explode('@', $repEmail)[0], 'company' => null, 'linkedin' => null, 'responseStatus' => 'accepted'];

        $items = [];
        $usedSlots = [];
        $count = $rng->getInt(2, 6);
        for ($i = 0; $i < $count; $i++) {
            $slot = $rng->getInt(0, 2) === 0 ? $rng->getInt(0, $this->procedural['shared_slots'] - 1) : null;
            if ($slot !== null && ! in_array($slot, $usedSlots, true)) {
                // Shared slot: details come from a per-(tenant, week, slot) PRNG,
                // so every rep who picks it sees an identical meeting.
                $usedSlots[] = $slot;
                $id = sprintf('proc-%s-%s-s%d', $seed, $week, $slot);
                $meetingRng = $this->rng($seed.'|slot|'.$week.'|'.$slot);
            } else {
                $id = sprintf('proc-%s-%s-%s-%d', $seed, $week, substr(md5($repEmail), 0, 12), $i);
                $meetingRng = $rng;
            }

            $start = $mondayUtc->addDays($meetingRng->getInt(0, 4))->setTime($meetingRng->getInt(12, 17), 0);
            $minutes = $meetingRng->getInt(0, 1) === 0 ? 30 : 60;
            $statusRoll = $meetingRng->getInt(0, 9);
            $status = $statusRoll === 0 ? 'cancelled' : ($statusRoll === 1 ? 'tentative' : 'confirmed');

            $attendees = [$self];
            $leadCount = $meetingRng->getInt(1, 2);
            for ($l = 0; $l < $leadCount; $l++) {
                $lead = $this->pool()[$meetingRng->getInt(0, $this->procedural['pool_size'] - 1)];
                $responseRoll = $meetingRng->getInt(0, 9);
                $attendees[] = [
                    'email' => $lead['email'],
                    'displayName' => $lead['name'],
                    'company' => $lead['company'],
                    'linkedin' => $lead['linkedin'],
                    'responseStatus' => $responseRoll === 0 ? 'declined' : ($responseRoll === 1 ? 'needsAction' : 'accepted'),
                ];
            }

            $items[] = $this->item($id, 'Meeting with '.$attendees[1]['company'], $start, $minutes, $status, $attendees);
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $attendees
     * @return array<string, mixed>
     */
    private function item(string $id, string $summary, CarbonImmutable $start, int $minutes, string $status, array $attendees): array
    {
        return [
            'id' => $id,
            'summary' => $summary,
            'start' => $start->toIso8601String(),
            'end' => $start->addMinutes($minutes)->toIso8601String(),
            'status' => $status,
            'attendees' => $attendees,
        ];
    }

    /**
     * Deterministic lead pool for procedural mode; every 5th lead has no LinkedIn.
     *
     * @return list<array{email: string, name: string, company: string, linkedin: ?string}>
     */
    private function pool(): array
    {
        if ($this->pool !== null) {
            return $this->pool;
        }

        ['first_names' => $first, 'last_names' => $last, 'companies' => $companies] = $this->procedural;
        $pool = [];
        for ($i = 0; $i < $this->procedural['pool_size']; $i++) {
            $f = $first[$i % count($first)];
            $l = $last[intdiv($i, count($first)) % count($last)];
            $c = $companies[$i % count($companies)];
            $slug = strtolower($f.'-'.$l.'-'.$i);
            $pool[] = [
                'email' => sprintf('%s.%s.%d@%s.test', strtolower($f), strtolower($l), $i, strtolower($c)),
                'name' => $f.' '.$l,
                'company' => $c,
                'linkedin' => $i % 5 === 0 ? null : 'https://www.linkedin.com/in/'.$slug,
            ];
        }

        return $this->pool = $pool;
    }

    private function rng(string $key): Randomizer
    {
        return new Randomizer(new Mt19937(crc32($key)));
    }

    private function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw $this->invalid($field);
        }

        $out = [];
        foreach ($value as $k => $v) {
            if (! is_string($k) || ! is_string($v)) {
                throw $this->invalid($field);
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw $this->invalid($field);
        }

        return array_map(fn (mixed $v): string => is_string($v) ? $v : throw $this->invalid($field), $value);
    }

    /**
     * @return array<string, array{email: string, name: string, company: ?string, linkedin: ?string}>
     */
    private function parsePeople(mixed $value): array
    {
        if (! is_array($value)) {
            throw $this->invalid('people');
        }

        $out = [];
        foreach ($value as $key => $p) {
            if (! is_string($key) || ! is_array($p) || ! is_string($p['email'] ?? null) || ! is_string($p['name'] ?? null)) {
                throw $this->invalid('people');
            }
            $company = $p['company'] ?? null;
            $linkedin = $p['linkedin'] ?? null;
            if (($company !== null && ! is_string($company)) || ($linkedin !== null && ! is_string($linkedin))) {
                throw $this->invalid('people');
            }
            $out[$key] = ['email' => $p['email'], 'name' => $p['name'], 'company' => $company, 'linkedin' => $linkedin];
        }

        return $out;
    }

    /**
     * @return list<array{key: string, summary: string, day: int, time: string, minutes: int, status: string, reps: list<string>, guests: array<string, string>}>
     */
    private function parseMeetings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->invalid('meetings');
        }

        $out = [];
        foreach ($value as $m) {
            if (! is_array($m)
                || ! is_string($m['key'] ?? null) || ! is_string($m['summary'] ?? null) || ! is_string($m['status'] ?? null)
                || ! is_int($m['day'] ?? null) || $m['day'] < 0 || $m['day'] > 6
                || ! is_int($m['minutes'] ?? null) || $m['minutes'] <= 0
                || ! is_string($m['time'] ?? null) || preg_match('/^\d{2}:\d{2}$/', $m['time']) !== 1) {
                throw $this->invalid('meetings');
            }

            $reps = $this->stringList($m['reps'] ?? null, 'meetings.reps');
            $guests = $this->stringMap($m['guests'] ?? null, 'meetings.guests');
            foreach ($reps as $rep) {
                if (! array_key_exists($rep, $this->reps)) {
                    throw $this->invalid('meetings.reps');
                }
            }
            foreach (array_keys($guests) as $person) {
                if (! array_key_exists($person, $this->people)) {
                    throw $this->invalid('meetings.guests');
                }
            }

            $out[] = [
                'key' => $m['key'], 'summary' => $m['summary'], 'day' => $m['day'], 'time' => $m['time'],
                'minutes' => $m['minutes'], 'status' => $m['status'], 'reps' => $reps, 'guests' => $guests,
            ];
        }

        return $out;
    }

    /**
     * @return array{first_names: list<string>, last_names: list<string>, companies: list<string>, pool_size: int, shared_slots: int}
     */
    private function parseProcedural(mixed $value): array
    {
        if (! is_array($value)
            || ! is_int($value['pool_size'] ?? null) || $value['pool_size'] < 1
            || ! is_int($value['shared_slots'] ?? null) || $value['shared_slots'] < 1) {
            throw $this->invalid('procedural');
        }

        return [
            'first_names' => $this->stringList($value['first_names'] ?? null, 'procedural.first_names'),
            'last_names' => $this->stringList($value['last_names'] ?? null, 'procedural.last_names'),
            'companies' => $this->stringList($value['companies'] ?? null, 'procedural.companies'),
            'pool_size' => $value['pool_size'],
            'shared_slots' => $value['shared_slots'],
        ];
    }

    private function invalid(string $field): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('Fake calendar scenario is malformed at "%s"', $field));
    }
}
