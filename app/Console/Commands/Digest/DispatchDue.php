<?php

declare(strict_types=1);

namespace App\Console\Commands\Digest;

use App\Jobs\SendDailyDigest;
use App\Models\Tenant;
use App\Services\Digest\DeliveryRecorder;
use App\Services\Digest\DueRepFinder;
use App\Support\JobLog;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled every minute (routes/console.php). For each tenant, claims the
 * `digest_deliveries` row of every rep whose local send window is open and
 * queues SendDailyDigest for the rows it claimed (PRD FR-10, FR-12).
 *
 * Invariants:
 *  - The row is inserted before the job is queued, and only the process whose
 *    insert succeeded queues it, so overlapping runs or servers can never
 *    queue two sends for one rep and local date.
 *  - A claimed row whose job cannot be queued is recorded as `failed_send`
 *    (reason `dispatch_failed`) with an alert, never left silently `pending`.
 *  - All tenant data is read inside TenantContext::run().
 */
final class DispatchDue extends Command
{
    protected $signature = 'digest:dispatch-due {--tenant= : Only this tenant id}';

    protected $description = 'Queue SendDailyDigest for reps whose local send window is open';

    public function handle(TenantContext $context, DueRepFinder $finder, DeliveryRecorder $recorder, JobLog $log): int
    {
        $tenants = Tenant::query()->orderBy('id');
        $only = $this->option('tenant');
        if ($only !== null) {
            if (! is_string($only) || ! ctype_digit($only)) {
                $this->error('--tenant must be a tenant id.');

                return self::FAILURE;
            }
            $tenants->whereKey((int) $only);
        }

        $total = 0;
        foreach ($tenants->get() as $tenant) {
            $total += $context->run($tenant, fn (Tenant $tenant): int => $this->dispatchFor($tenant, $finder, $recorder, $log));
        }

        $this->info(sprintf('Dispatched %d digest(s).', $total));

        return self::SUCCESS;
    }

    private function dispatchFor(Tenant $tenant, DueRepFinder $finder, DeliveryRecorder $recorder, JobLog $log): int
    {
        $sendTime = sprintf('%02d:00', config()->integer('digest.send_hour'));
        $dispatched = 0;

        foreach ($finder->dueNow() as $rep) {
            $clock = $rep->clock();
            $localToday = $clock->today();

            $delivery = $recorder->claim($rep, $localToday, $clock->atLocalTime($localToday, $sendTime));
            if ($delivery === null) {
                continue;
            }

            try {
                SendDailyDigest::dispatch($tenant->id, $delivery->id);
                $dispatched++;
            } catch (Throwable $e) {
                if ($recorder->markFailedSend($delivery, 'dispatch_failed')) {
                    $log->error('digest.failed_send', [
                        'tenant_id' => $tenant->id,
                        'rep_id' => $rep->id,
                        'local_date' => $localToday->toDateString(),
                        'reason' => 'dispatch_failed',
                        'exception' => $e::class,
                    ]);
                }
            }
        }

        if ($dispatched > 0) {
            $log->info('digest.dispatched', ['tenant_id' => $tenant->id, 'calls' => $dispatched]);
        }

        return $dispatched;
    }
}
