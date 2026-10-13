<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The tenant's daily provider-call cap (A06) was hit; the call was not made.
 */
final class ProviderCallCapReachedException extends RuntimeException
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $cap,
    ) {
        parent::__construct(sprintf('Provider call cap of %d reached for tenant %d', $cap, $tenantId));
    }
}
