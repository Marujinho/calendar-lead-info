<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Data\AttendeeData;
use App\Models\Tenant;

/**
 * Decides which meeting attendees are stored as leads (PRD FR-6).
 *
 * An attendee is a lead when their email domain is not one of the tenant's
 * internal domains and their email is not any rep of the tenant (active or
 * inactive, any domain). Matching is case-insensitive.
 *
 * Invariant: the response status is deliberately ignored here. A declined
 * attendee is still stored, with its status on `meeting_lead`, so re-syncing
 * the same payload is idempotent; digests and history counts exclude declined
 * links on the read side.
 */
final readonly class LeadClassifier
{
    /** @var array<string, true> */
    private array $internalDomains;

    /** @var array<string, true> */
    private array $repEmails;

    /**
     * @param  list<string>  $internalDomains
     * @param  list<string>  $repEmails
     */
    public function __construct(array $internalDomains, array $repEmails)
    {
        $this->internalDomains = array_fill_keys(array_map(self::normalise(...), $internalDomains), true);
        $this->repEmails = array_fill_keys(array_map(self::normalise(...), $repEmails), true);
    }

    /**
     * Loads every rep email of the tenant once (one query). Must run with
     * `$tenant` as the current tenant in TenantContext.
     */
    public static function forTenant(Tenant $tenant): self
    {
        /** @var list<string> $repEmails */
        $repEmails = $tenant->reps()->pluck('email')->all();

        return new self($tenant->internalDomains(), $repEmails);
    }

    public function isLead(AttendeeData $attendee): bool
    {
        $domain = $attendee->domain();

        if ($domain === '') {
            return false;
        }

        return ! isset($this->internalDomains[$domain]) && ! isset($this->repEmails[$attendee->email]);
    }

    private static function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
