<?php

declare(strict_types=1);

namespace App\Console\Commands\Rep;

use App\Console\Commands\Concerns\ReadsStringInput;
use App\Console\Commands\Concerns\ValidatesRepInput;
use App\Models\Rep;
use App\Models\Tenant;
use App\Support\JobLog;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * `rep:deactivate` — flips a rep's kill switch (`active = false`) so the next
 * cycle neither syncs nor emails them (PRD FR-15).
 *
 * Invariants: looks the rep up only inside the given tenant's context, never
 * deletes data, and is idempotent (an already inactive rep is a success).
 */
final class DeactivateRepCommand extends Command
{
    use ReadsStringInput;
    use ValidatesRepInput;

    protected $signature = 'rep:deactivate
        {tenant : Tenant id}
        {email : Rep email address (case-insensitive)}';

    protected $description = 'Deactivate a sales rep: no more syncs or digests';

    public function handle(TenantContext $context, JobLog $log): int
    {
        $tenant = $this->findTenant($this->stringArgument('tenant'));
        if ($tenant === null) {
            $this->error('Unknown tenant.');

            return self::FAILURE;
        }

        $email = $this->normaliseEmail($this->stringArgument('email'));
        if ($email === null) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        $rep = $context->run($tenant, static fn (Tenant $t): ?Rep => Rep::query()->where('email', $email)->first());

        if ($rep === null) {
            $this->error('No rep with this email in this tenant.');

            return self::FAILURE;
        }

        if (! $rep->active) {
            $this->warn(sprintf('Rep %d is already inactive; nothing changed.', $rep->id));

            return self::SUCCESS;
        }

        $context->run($tenant, static fn (Tenant $t): bool => $rep->update(['active' => false]));

        $log->info('rep.deactivated', ['tenant_id' => $tenant->id, 'rep_id' => $rep->id]);
        $this->info(sprintf('Rep %d deactivated.', $rep->id));

        return self::SUCCESS;
    }
}
