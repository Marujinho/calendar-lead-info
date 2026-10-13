<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The provider did not answer within `config('calendar.timeout')` seconds.
 * Separate class so the metering decorator records CallOutcome::Timeout.
 */
final class ProviderTimeoutException extends ProviderUnavailableException
{
    public static function after(int $seconds): self
    {
        return new self(sprintf('Calendar provider timed out after %ds', $seconds));
    }
}
