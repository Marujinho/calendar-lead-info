<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\Enums\ResponseStatus;

/**
 * One attendee of a provider meeting, as the provider reports them.
 *
 * Invariant: `email` is always trimmed and lower-cased so every comparison
 * downstream (internal domains, rep emails, lead identity) is case-insensitive
 * without each caller remembering to normalise.
 */
final readonly class AttendeeData
{
    public string $email;

    public function __construct(
        string $email,
        public string $fullName,
        public ?string $companyName = null,
        public ?string $linkedinUrl = null,
        public ResponseStatus $responseStatus = ResponseStatus::Accepted,
    ) {
        $this->email = mb_strtolower(trim($email));
    }

    public function domain(): string
    {
        $at = strrpos($this->email, '@');

        return $at === false ? '' : substr($this->email, $at + 1);
    }
}
