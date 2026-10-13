<?php

declare(strict_types=1);

namespace App\Console\Commands\Meetings;

use App\Jobs\PurgeOldMeetings;
use Illuminate\Console\Command;

/**
 * Entry point of the daily retention purge (PRD FR-17), scheduled in
 * routes/console.php. Queues PurgeOldMeetings on the `maintenance` queue, or
 * runs it in-process with `--sync`.
 *
 * Invariant: the purge itself lives in the job, so the scheduled and manual
 * paths delete exactly the same rows.
 */
final class Purge extends Command
{
    protected $signature = 'meetings:purge {--sync : Run the purge in this process instead of queueing it}';

    protected $description = 'Purge meetings older than the retention window and orphan leads';

    public function handle(): int
    {
        if ($this->option('sync') === true) {
            PurgeOldMeetings::dispatchSync();
            $this->info('Purge completed.');

            return self::SUCCESS;
        }

        PurgeOldMeetings::dispatch();
        $this->info(sprintf('Purge queued on "%s".', (string) config('queue.queues.maintenance')));

        return self::SUCCESS;
    }
}
