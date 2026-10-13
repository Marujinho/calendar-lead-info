<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Exceptions\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that pins every query of a tenant-owned model to the tenant in
 * TenantContext. With no tenant set it throws instead of returning everything.
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->has()) {
            throw MissingTenantContextException::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->currentId());
    }
}
