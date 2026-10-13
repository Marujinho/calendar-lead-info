<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FakeCalendarProvider demo scenario
|--------------------------------------------------------------------------
|
| Hand-written weekly template for the demo reps. Every ISO week returns the
| same meetings, positioned by `day` (0 = Monday .. 6 = Sunday) and `time`
| (HH:MM, UTC) relative to that week's Monday, so the scenario is valid for
| any week and past weeks (backfill) look like the current one. External ids
| are suffixed with the week's Monday date, so each week's occurrence is a
| distinct meeting and a meeting listed under several `reps` carries the same
| id on each of their calendars.
|
| All times sit between 12:00 and 18:00 UTC, so every meeting lands on the
| same local calendar day for America/Sao_Paulo and Europe/Lisbon reps.
|
| Expected 30-day counts per lead are documented in docs/calendar-provider.md
| and pinned by tests/Unit/Calendar/DemoScenarioCountsTest.php. Change both
| when this file changes.
|
| Any rep email not listed under `reps` gets procedurally generated weeks
| (load tests), built from the `procedural` pools below.
|
*/

return [

    // Rep email => display name. acme.test is the demo tenant's internal
    // domain; zoe@beta.test belongs to a second demo tenant (beta.test).
    'reps' => [
        'ana@acme.test' => 'Ana Costa',
        'rui@acme.test' => 'Rui Mendes',
        'lee@acme.test' => 'Lee Park',
        'zoe@beta.test' => 'Zoe Adams',
    ],

    // Non-rep attendees. `linkedin` null = the lead has no LinkedIn (FR-8 "—").
    'people' => [
        'maria' => ['email' => 'maria.silva@globex.test', 'name' => 'Maria Silva', 'company' => 'Globex', 'linkedin' => 'https://www.linkedin.com/in/maria-silva-demo'],
        'tom' => ['email' => 'tom.baker@initech.test', 'name' => 'Tom Baker', 'company' => 'Initech', 'linkedin' => null],
        'priya' => ['email' => 'priya.nair@hooli.test', 'name' => 'Priya Nair', 'company' => 'Hooli', 'linkedin' => 'https://www.linkedin.com/in/priya-nair-demo'],
        'sam' => ['email' => 'sam.jones@hooli.test', 'name' => 'Sam Jones', 'company' => 'Hooli', 'linkedin' => 'https://www.linkedin.com/in/sam-jones-demo'],
        'li' => ['email' => 'li.wei@umbrella.test', 'name' => 'Li Wei', 'company' => 'Umbrella', 'linkedin' => 'https://www.linkedin.com/in/li-wei-demo'],
        'omar' => ['email' => 'omar.haddad@stark.test', 'name' => 'Omar Haddad', 'company' => 'Stark', 'linkedin' => 'https://www.linkedin.com/in/omar-haddad-demo'],
        'joana' => ['email' => 'joana@acme.test', 'name' => 'Joana Reis', 'company' => 'Acme', 'linkedin' => null],
    ],

    // `reps` attend with responseStatus accepted; `guests` map person => responseStatus.
    'meetings' => [
        // Internal-only (no lead), shared by all three acme reps.
        ['key' => 'pipeline-review', 'summary' => 'Weekly pipeline review', 'day' => 0, 'time' => '12:00', 'minutes' => 60, 'status' => 'confirmed',
            'reps' => ['ana@acme.test', 'rui@acme.test', 'lee@acme.test'], 'guests' => ['joana' => 'accepted']],
        ['key' => 'globex-discovery', 'summary' => 'Globex discovery call', 'day' => 0, 'time' => '14:00', 'minutes' => 30, 'status' => 'confirmed',
            'reps' => ['ana@acme.test'], 'guests' => ['maria' => 'accepted']],
        // Lead with no LinkedIn, meeting two different reps (Tue with rui, Wed with lee).
        ['key' => 'initech-demo', 'summary' => 'Initech product demo', 'day' => 1, 'time' => '13:00', 'minutes' => 45, 'status' => 'confirmed',
            'reps' => ['rui@acme.test'], 'guests' => ['tom' => 'accepted']],
        // Shared by two reps: same external id on ana's and rui's calendars.
        ['key' => 'globex-roadmap', 'summary' => 'Globex roadmap review', 'day' => 2, 'time' => '14:00', 'minutes' => 60, 'status' => 'confirmed',
            'reps' => ['ana@acme.test', 'rui@acme.test'], 'guests' => ['maria' => 'accepted', 'joana' => 'accepted']],
        ['key' => 'initech-followup', 'summary' => 'Initech follow-up', 'day' => 2, 'time' => '15:00', 'minutes' => 30, 'status' => 'confirmed',
            'reps' => ['lee@acme.test'], 'guests' => ['tom' => 'accepted']],
        // Declined attendee: sam is never a lead here; priya is.
        ['key' => 'hooli-pricing', 'summary' => 'Hooli pricing', 'day' => 2, 'time' => '17:00', 'minutes' => 45, 'status' => 'confirmed',
            'reps' => ['ana@acme.test'], 'guests' => ['priya' => 'accepted', 'sam' => 'declined']],
        // Cancelled: stored, never counted.
        ['key' => 'umbrella-intro', 'summary' => 'Umbrella intro', 'day' => 3, 'time' => '16:00', 'minutes' => 30, 'status' => 'cancelled',
            'reps' => ['ana@acme.test'], 'guests' => ['li' => 'accepted']],
        // Tentative meeting, tentative and needsAction attendees: all counted.
        ['key' => 'umbrella-tech', 'summary' => 'Umbrella technical deep-dive', 'day' => 4, 'time' => '13:00', 'minutes' => 60, 'status' => 'tentative',
            'reps' => ['lee@acme.test'], 'guests' => ['li' => 'tentative', 'omar' => 'needsAction']],
        // Second tenant: maria is a lead there too (same email, different tenant).
        ['key' => 'globex-beta-intro', 'summary' => 'Globex intro', 'day' => 1, 'time' => '15:00', 'minutes' => 30, 'status' => 'confirmed',
            'reps' => ['zoe@beta.test'], 'guests' => ['maria' => 'accepted']],
    ],

    // Procedural mode: 2-6 meetings per rep per week, leads drawn from a pool
    // of `pool_size` fake people, `shared_slots` tenant-wide meetings per week
    // that several reps can share (same external id).
    'procedural' => [
        'first_names' => ['Alex', 'Bea', 'Caio', 'Dana', 'Eli', 'Fran', 'Gil', 'Hana', 'Ivo', 'Jo', 'Kai', 'Lia'],
        'last_names' => ['Moreau', 'Novak', 'Okafor', 'Pereira', 'Quinn', 'Rossi', 'Sato'],
        'companies' => ['Acorn', 'Birch', 'Cedar', 'Delta', 'Ember', 'Fjord'],
        'pool_size' => 40,
        'shared_slots' => 10,
    ],

];
