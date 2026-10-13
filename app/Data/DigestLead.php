<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One lead row as it appears in the email.
 *
 * `meetingsLast30Days` is the tenant-wide count of distinct held meetings the
 * lead attended in the 30 days before the rep's local today (PRD FR-9).
 */
final readonly class DigestLead
{
    public function __construct(
        public string $email,
        public string $fullName,
        public ?string $companyName,
        public ?string $linkedinUrl,
        public int $meetingsLast30Days,
    ) {}
}
