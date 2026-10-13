# ADR-0001: Calendar source behind a Strategy, built per tenant, metered by a decorator

**Status**: accepted · **Date**: 2026-10-13

## Context
The scope requires the Strategy pattern so the fake calendar source can be
replaced by the real one without touching the rest of the system. Credentials
are per tenant, and every call costs money, so calls must be logged and capped.

## Decision
- `CalendarProvider::fetchWeek(repEmail, anyDayInWeek): WeekSchedule` is the
  only way meetings enter the system. Implementations only fetch and map.
- A `CalendarProviderFactory::forTenant(Tenant)` builds the provider for one
  tenant with that tenant's credentials. The implementation class comes from
  `config('calendar.provider')`.
- The factory wraps every provider in `MeteredCalendarProvider`, which writes
  a `provider_calls` row per call, enforces the per-tenant daily cap and
  classifies outcomes (success / failure / timeout / rejected_cap).

## Consequences
- Adding the real provider = one class + one config entry (PRD FR-1).
- A real adapter never re-implements metering, so cost accounting cannot drift
  between implementations.
- Jobs depend on the factory, not on a provider singleton, which is what makes
  per-tenant credentials possible (PRD A07).
