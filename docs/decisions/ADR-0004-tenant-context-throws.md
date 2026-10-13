# ADR-0004: Tenant scope that throws when no tenant is in context

**Status**: accepted · **Date**: 2026-10-13

## Context
The product is tenant-ready. The PRD asks that "every tenant query is scoped"
and that counts never cross tenants. A convention ("remember to add
where tenant_id") cannot be tested. A global scope that silently falls back to
"no filter" when nothing is set is worse than no scope: it looks safe and isn't.

## Decision
- `TenantContext` is a scoped singleton holding the current tenant.
- `BelongsToTenant` adds `TenantScope`, which reads the context and **throws**
  `MissingTenantContextException` when it is empty, and fills `tenant_id` on
  create.
- Jobs implement `TenantScopedJob` and run under the `SetTenantContext`
  middleware; commands wrap work in `TenantContext::run()`.
- Pivot tables carry `tenant_id` too, and an architecture test asserts every
  model except `Tenant` uses the trait and has the column.
- `withoutGlobalScopes()` is not used anywhere. The purge iterates tenants.

## Consequences
- An unscoped query is a failing test or a crashing job, never a data leak.
- Every test touching tenant data starts with `actingAsTenant()`.
- Raw query-builder writes (`upsert`) must include `tenant_id` explicitly.
