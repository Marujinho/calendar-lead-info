<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Tenant;
use App\Support\JobLog;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Daily retention purge (PRD FR-17): meetings that started more than
 * `digest.retention_days` days ago, their pivot rows (FK cascade), then leads
 * left with no meeting link.
 *
 * Invariants:
 *  - Not tenant-scoped itself: it iterates tenants and enters each one with
 *    TenantContext::run, so every delete is pinned to one tenant and no query
 *    bypasses the TenantScope.
 *  - Deletes in id chunks (select ids, then delete by id), because MySQL
 *    rejects `DELETE … JOIN … LIMIT` and an unbounded delete would hold locks.
 *  - A failure in one tenant is logged as an alert and the loop continues.
 */
final class PurgeOldMeetings implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 500;

    public function __construct()
    {
        $this->onQueue(config('queue.queues.maintenance'));
    }

    public function handle(TenantContext $context, JobLog $log): void
    {
        $cutoff = CarbonImmutable::now()->subDays((int) config('digest.retention_days'));

        foreach (Tenant::all() as $tenant) {
            try {
                [$meetings, $leads] = $context->run($tenant, fn (): array => [
                    $this->deleteInChunks(Meeting::query()->where('start_at', '<', $cutoff)),
                    $this->deleteInChunks(Lead::query()->whereDoesntHave('meetings')),
                ]);

                $log->info('purge.completed', ['tenant_id' => $tenant->id, 'meeting_count' => $meetings, 'lead_count' => $leads]);
            } catch (Throwable $e) {
                $log->error('purge.failed', ['tenant_id' => $tenant->id, 'exception' => $e::class]);
            }
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function deleteInChunks(Builder $query): int
    {
        $total = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
            $deleted = $ids === [] ? 0 : (int) $query->getModel()->newQuery()->whereIn('id', $ids)->delete();
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
