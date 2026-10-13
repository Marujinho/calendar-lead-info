<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider could not be reached or returned an error.
 * Messages carry reason codes only, never payload content (PRD A09).
 */
class ProviderUnavailableException extends RuntimeException
{
    public static function because(string $reasonCode, ?Throwable $previous = null): self
    {
        return new self(sprintf('Calendar provider unavailable: %s', $reasonCode), 0, $previous);
    }
}
