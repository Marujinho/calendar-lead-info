<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A provider payload (or one meeting inside it) failed validation into DTOs.
 *
 * Invariant: `$field` and `$reason` are identifiers, never the offending value,
 * so the exception can be logged without leaking attendee data.
 */
final class InvalidProviderPayloadException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
        public readonly ?string $externalId = null,
    ) {
        parent::__construct(sprintf('Invalid provider payload: %s %s', $field, $reason));
    }
}
