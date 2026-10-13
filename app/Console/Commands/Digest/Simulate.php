<?php

declare(strict_types=1);

namespace App\Console\Commands\Digest;

use App\Contracts\DigestBuilder;
use App\Contracts\DigestMailer;
use App\Models\DigestDelivery;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Throwable;

/**
 * Local verification tool (PRD §18): replays one morning at a frozen instant
 * and processes every job inline, so the resulting emails land in Mailpit.
 *
 * 1. If `calendar:dispatch-sync` exists, freezes the clock at `sync_hour:00`
 *    of the same local day in the `--at` zone and runs it.
 * 2. Freezes the clock at `--at` and runs `digest:dispatch-due`.
 * 3. Prints the status of every delivery scheduled for that slot.
 *
 * Jobs run on the `sync` queue connection for the duration of the command,
 * so queue retries and backoff do not happen: a transport error goes
 * straight to `failed_send`. Only reps in zones whose send window is open
 * at `--at` are processed, exactly as the scheduler would.
 *
 * Invariant: the frozen clock and the queue connection are restored before
 * the command returns, whatever happened inside.
 */
final class Simulate extends Command
{
    protected $signature = 'digest:simulate
        {--at= : Instant to replay, "Y-m-d H:i [IANA zone]" (zone defaults to UTC; default now)}
        {--tenant= : Only this tenant id}';

    protected $description = 'Replay the sync and digest dispatchers at a frozen instant, processing jobs inline';

    public function handle(TenantContext $context): int
    {
        $at = $this->option('at');
        $tenantOption = $this->option('tenant');

        try {
            $instant = is_string($at) && $at !== '' ? self::parseAt($at) : CarbonImmutable::now();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($tenantOption !== null && (! is_string($tenantOption) || ! ctype_digit($tenantOption))) {
            $this->error('--tenant must be a tenant id.');

            return self::FAILURE;
        }

        foreach ([DigestBuilder::class, DigestMailer::class] as $contract) {
            if (! $this->laravel->bound($contract)) {
                $this->error(sprintf('%s has no implementation bound; nothing can be sent.', class_basename($contract)));

                return self::FAILURE;
            }
        }

        $tenantArgs = $tenantOption !== null ? ['--tenant' => $tenantOption] : [];
        $previousNow = CarbonImmutable::getTestNow();
        $previousConnection = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $this->runSyncPhase($instant, $tenantArgs);

            $this->freeze($instant);
            $this->line(sprintf('Clock frozen at %s (%s UTC).', $instant->toDateTimeString(), $instant->utc()->toDateTimeString()));
            Artisan::call('digest:dispatch-due', $tenantArgs, $this->output);

            $this->report($context, $instant, $tenantOption);
        } finally {
            config(['queue.default' => $previousConnection]);
            $this->freeze($previousNow);
        }

        return self::SUCCESS;
    }

    /**
     * Parse `Y-m-d H:i` optionally followed by an IANA zone, e.g.
     * "2026-10-14 08:00 Europe/Lisbon". The result is in that zone (UTC when
     * none is given).
     *
     * @throws InvalidArgumentException on any other shape or an unknown zone
     */
    public static function parseAt(string $value): CarbonImmutable
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        if (count($parts) < 2 || count($parts) > 3) {
            throw new InvalidArgumentException('--at must look like "2026-10-14 08:00 Europe/Lisbon".');
        }

        $zone = $parts[2] ?? 'UTC';
        if (! in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown IANA timezone "%s".', $zone));
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i', $parts[0].' '.$parts[1], $zone);
        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d H:i') !== $parts[0].' '.$parts[1]) {
            throw new InvalidArgumentException('--at must look like "2026-10-14 08:00 Europe/Lisbon".');
        }

        return $parsed;
    }

    /**
     * @param  array<string, string>  $tenantArgs
     */
    private function runSyncPhase(CarbonImmutable $instant, array $tenantArgs): void
    {
        $command = Artisan::all()['calendar:dispatch-sync'] ?? null;
        if ($command === null) {
            $this->line('calendar:dispatch-sync is not installed; skipping the sync phase.');

            return;
        }

        $syncAt = $instant->startOfDay()->setTime(config()->integer('digest.sync_hour'), 0);
        if ($syncAt->greaterThan($instant)) {
            $this->line('--at is before the local sync hour; skipping the sync phase.');

            return;
        }

        $args = $command->getDefinition()->hasOption('tenant') ? $tenantArgs : [];

        $this->freeze($syncAt);
        $this->line(sprintf('Clock frozen at %s for calendar:dispatch-sync.', $syncAt->toDateTimeString()));

        try {
            Artisan::call('calendar:dispatch-sync', $args, $this->output);
        } catch (Throwable $e) {
            // The digest must still go out on stale data (FR-11); show why the sync failed and carry on.
            $this->warn(sprintf('calendar:dispatch-sync raised %s; continuing with stored data.', $e::class));
        }
    }

    private function report(TenantContext $context, CarbonImmutable $instant, ?string $tenantOption): void
    {
        $tenants = Tenant::query()->orderBy('id');
        if ($tenantOption !== null) {
            $tenants->whereKey((int) $tenantOption);
        }

        $windowStart = $instant->utc()->subMinutes(config()->integer('digest.send_window_minutes'));
        $rows = [];

        foreach ($tenants->get() as $tenant) {
            $deliveries = $context->run($tenant, static fn () => DigestDelivery::query()
                ->where('scheduled_for', '>', $windowStart)
                ->where('scheduled_for', '<=', $instant->utc())
                ->orderBy('id')
                ->get());

            foreach ($deliveries as $delivery) {
                $rows[] = [
                    $tenant->id,
                    $delivery->rep_id,
                    $delivery->local_date->toDateString(),
                    $delivery->status->value,
                    $delivery->failure_reason ?? '',
                ];
            }
        }

        if ($rows === []) {
            $this->line('No deliveries are scheduled for this slot.');

            return;
        }

        $this->table(['tenant', 'rep', 'local date', 'status', 'reason'], $rows);
    }

    private function freeze(?CarbonImmutable $instant): void
    {
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }
}
