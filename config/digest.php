<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily Digest Schedule
    |--------------------------------------------------------------------------
    |
    | All hours and times are in each rep's own IANA timezone, resolved
    | through RepClock. Nothing here is UTC.
    |
    */

    // Local hour (0-23) at which the digest is sent.
    'send_hour' => (int) env('DIGEST_SEND_HOUR', 8),

    // Minutes after send_hour during which sending and mail retries may run
    // (08:00-08:15 by default). After the window a delivery is marked failed.
    'send_window_minutes' => (int) env('DIGEST_SEND_WINDOW_MINUTES', 15),

    // Local hour (0-23) at which the calendar sync for the day starts.
    'sync_hour' => (int) env('DIGEST_SYNC_HOUR', 5),

    // Local time (HH:MM) after which no further sync attempt is started, so
    // fetching never competes with sending.
    'sync_cutoff' => env('DIGEST_SYNC_CUTOFF', '07:45'),

    // Days of meeting history kept; older meetings and orphan leads are purged.
    'retention_days' => (int) env('DIGEST_RETENTION_DAYS', 35),

];
