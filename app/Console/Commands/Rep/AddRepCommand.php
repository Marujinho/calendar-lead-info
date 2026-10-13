<?php

declare(strict_types=1);

namespace App\Console\Commands\Rep;

use App\Console\Commands\Concerns\ReadsStringInput;
use App\Console\Commands\Concerns\ValidatesRepInput;
use App\Models\Tenant;
use App\Support\JobLog;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * `rep:add` — registers one sales rep for a tenant (PRD FR-15).
 *
 * Invariants: the rep is written inside the tenant's context, at most once per
 * (tenant, email) case-insensitively, and `RepCreated` fires only for a newly
 * inserted row so the sync module backfills it exactly once. No job is
 * dispatched here.
 */
final class AddRepCommand extends Command
{
    use ReadsStringInput;
    use ValidatesRepInput;

    protected $signature = 'rep:add
        {tenant : Tenant id}
        {email : Rep email address (stored lower-cased)}
        {name : Rep display name}
        {--timezone= : IANA timezone; defaults to the tenant\'s default timezone}';

    protected $description = 'Add a sales rep to a tenant';

    public function handle(TenantContext $context, JobLog $log): int
    {
        $tenant = $this->findTenant($this->stringArgument('tenant'));
        if ($tenant === null) {
            $this->error('Unknown tenant.');

            return self::FAILURE;
        }

        $email = $this->stringArgument('email');
        $name = $this->stringArgument('name');
        $timezone = $this->stringOption('timezone') ?? $tenant->default_timezone;

        $error = $this->repInputError($email, $name, $timezone);
        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $rep = $context->run($tenant, fn (Tenant $t) => $this->createRep($log, $email, $name, $timezone));

        if ($rep === null) {
            $this->error('A rep with this email already exists in this tenant.');

            return self::FAILURE;
        }

        $this->info(sprintf('Rep created: id %d (timezone %s).', $rep->id, $rep->timezone));

        return self::SUCCESS;
    }
}
