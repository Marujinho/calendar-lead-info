<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/**
 * Thrown when tenant-scoped data is queried with no tenant in TenantContext.
 * Failing loudly here is what makes FR-16 ("no unscoped tenant queries") a
 * mechanism instead of a convention.
 */
final class MissingTenantContextException extends LogicException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            'No tenant in context while querying %s. Wrap the call in TenantContext::run() or apply the SetTenantContext job middleware.',
            $modelClass,
        ));
    }
}
