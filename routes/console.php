<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled commands
|--------------------------------------------------------------------------
|
| Every entry runs on one server (database cache lock) and never overlaps
| itself. The two dispatchers run every minute because they decide per rep,
| in the rep's own timezone, whether that rep is due (PRD FR-4, FR-10).
| Command names here are fixed; the implementing modules add the commands.
|
*/

Schedule::command('calendar:dispatch-sync')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Dispatch SyncRepWeek for reps whose local sync window is open');

Schedule::command('digest:dispatch-due')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Dispatch SendDailyDigest for reps whose local 08:00 window is open');

Schedule::command('meetings:purge')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Purge meetings older than the retention window and orphan leads');
