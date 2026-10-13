<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Events\RepCreated;
use App\Models\Rep;
use App\Models\Tenant;
use App\Support\JobLog;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Validation and creation rules shared by `rep:add`, `rep:import` and
 * `rep:deactivate`, so one rep row is judged the same way whichever command
 * receives it (PRD FR-15).
 *
 * Invariants:
 *  - Emails are compared trimmed and lower-cased; at most one rep per
 *    (tenant, email), also under a concurrent insert (the unique index wins).
 *  - Timezones must be IANA identifiers.
 *  - `RepCreated` fires only after the row is committed, and only for a row
 *    this call actually inserted.
 *  - Logs carry ids only, never the email or name.
 */
trait ValidatesRepInput
{
    private const MAX_LENGTH = 255;

    /** Trimmed, lower-cased email, or null when it is not a valid address. */
    protected function normaliseEmail(string $email): ?string
    {
        $email = mb_strtolower(trim($email));

        if ($email === '' || mb_strlen($email) > self::MAX_LENGTH) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    protected function isKnownTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Why a rep row is invalid, or null when it is valid. The message never
     * repeats the input, so it is safe to print next to a CSV line number.
     */
    protected function repInputError(string $email, string $name, string $timezone): ?string
    {
        if ($this->normaliseEmail($email) === null) {
            return 'Invalid email address.';
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_LENGTH) {
            return sprintf('Name must be 1 to %d characters.', self::MAX_LENGTH);
        }

        if (! $this->isKnownTimezone($timezone)) {
            return 'Unknown timezone; use an IANA identifier such as Europe/Lisbon.';
        }

        return null;
    }

    /** The tenant with this id, or null when the id is malformed or unknown. */
    protected function findTenant(string $id): ?Tenant
    {
        if (preg_match('/^[1-9]\d{0,18}$/', $id) !== 1) {
            return null;
        }

        return Tenant::query()->find((int) $id);
    }

    /**
     * Insert a rep for the tenant in context and announce it. Returns null when
     * a rep with that email already exists in the tenant. The caller must have
     * validated the input with `repInputError()` and be inside
     * `TenantContext::run()`.
     */
    protected function createRep(JobLog $log, string $email, string $name, string $timezone): ?Rep
    {
        $email = $this->normaliseEmail($email) ?? throw new InvalidArgumentException('createRep() needs a validated email');

        $rep = DB::transaction(static function () use ($email, $name, $timezone): ?Rep {
            if (Rep::query()->where('email', $email)->exists()) {
                return null;
            }

            try {
                return Rep::query()->create([
                    'email' => $email,
                    'name' => trim($name),
                    'timezone' => $timezone,
                    'active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        });

        if ($rep === null) {
            return null;
        }

        $log->info('rep.created', ['tenant_id' => $rep->tenant_id, 'rep_id' => $rep->id]);
        event(new RepCreated($rep->tenant_id, $rep->id));

        return $rep;
    }
}
