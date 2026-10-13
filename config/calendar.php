<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Calendar Provider
    |--------------------------------------------------------------------------
    |
    | Which CalendarProvider implementation the per-tenant factory builds.
    | `fake` is the deterministic local provider; the real provider is added
    | when it is delivered. Only app/Services/Calendar reads this section.
    |
    */

    'provider' => env('CALENDAR_PROVIDER', 'fake'),

    /*
    | Safety switch for production: when APP_ENV=production the fake provider
    | is refused at boot unless this is explicitly true (PRD §12 A02).
    */

    'allow_fake' => (bool) env('CALENDAR_ALLOW_FAKE', false),

    /*
    | Hard timeout, in seconds, for a single provider call (PRD A10: <= 10 s).
    */

    'timeout' => (int) env('CALENDAR_PROVIDER_TIMEOUT', 10),

    /*
    | Per-tenant, per-UTC-day call cap: multiplier x active reps (+ 6 x reps
    | created that day). 3 lets a full-outage retry storm still give every
    | rep its three attempts (PRD §12 A06).
    */

    'cap_multiplier' => (int) env('CALENDAR_CAP_MULTIPLIER', 3),

    /*
    |--------------------------------------------------------------------------
    | Fake Provider
    |--------------------------------------------------------------------------
    |
    | mode:       how the fake behaves (`ok` returns fixture data; other modes
    |             such as `fail` simulate outages for local runs and tests).
    | latency_ms: artificial delay added to every fake call, in milliseconds.
    |
    */

    'fake' => [
        'mode' => env('CALENDAR_FAKE_MODE', 'ok'),
        'latency_ms' => (int) env('CALENDAR_FAKE_LATENCY_MS', 0),
    ],

];
