# Architecture

A Laravel 13 service with no web UI. It runs as scheduled artisan commands and
queue workers, talks to one external system (the calendar provider) through a
Strategy interface, stores what it fetched in MySQL, and sends one email per
sales rep per day. See `docs/data-model.md` for tables and
`docs/decisions/` for why things are the way they are.

## The daily cycle

```mermaid
sequenceDiagram
    autonumber
    participant S as Scheduler (every minute, onOneServer)
    participant SQ as sync queue
    participant P as CalendarProvider (via factory + Metered decorator)
    participant DB as MySQL
    participant DQ as digest queue
    participant M as Mail (SES / Mailpit)

    Note over S: 05:00 rep-local
    S->>SQ: calendar:dispatch-sync → SyncRepWeek(tenant, rep, week of tomorrow)
    SQ->>P: fetchWeek(repEmail, tomorrow)
    P-->>SQ: WeekSchedule (DTOs)
    SQ->>DB: upsert meetings, leads, pivots; mark rep_week_syncs success
    Note over S: 08:00 rep-local
    S->>DB: digest:dispatch-due → insert digest_deliveries(pending) (unique rep+date)
    S->>DQ: SendDailyDigest(delivery id) — only if the insert succeeded
    DQ->>DB: DigestBuilder reads today's meetings + 30-day counts (bounded queries)
    DQ->>DB: freshness from rep_week_syncs → stale notice / failed_no_data
    DQ->>DB: status = sending
    DQ->>M: DailyLeadBriefing
    DQ->>DB: status = sent (or skipped_empty / failed_*)
```

Two rules keep the 08:00 send safe from the provider:

1. **Sending never calls the provider.** `DigestBuilder` reads MySQL only.
2. **Sync and send live on different queues** (`sync`, `digest`, plus
   `maintenance` for the purge) with separate `queue:work` processes, so a slow
   or stuck fetch cannot delay a send.

## Boundaries

| Boundary | Where | Rule |
|---|---|---|
| Calendar source | `App\Contracts\CalendarProvider` | The only way meetings enter the system. `fetchWeek(repEmail, anyDayInWeek)` returns the ISO week containing that day. Implementations only fetch and map. |
| Per-tenant construction | `App\Contracts\CalendarProviderFactory::forTenant()` | Chooses the implementation from `config('calendar.provider')`, injects that tenant's `ProviderCredentials`, and wraps the result in `MeteredCalendarProvider` (call logging to `provider_calls`, daily cap, outcome classification). Jobs resolve the factory, never a provider. |
| DTO boundary | `App\Data\*` (`final readonly`) | Nothing past the provider sees raw arrays. Emails are normalised (trimmed, lower-cased) on construction so every comparison is case-insensitive. Statuses are enums in `App\Data\Enums`. |
| Tenant isolation | `App\Tenancy\TenantContext` + `BelongsToTenant` + `TenantScope` | Every model except `Tenant` is scoped to the tenant in context. Querying with no tenant set **throws** `MissingTenantContextException`. Jobs get the context from the `SetTenantContext` middleware (`TenantScopedJob::tenantId()`); commands call `TenantContext::run()`. |
| Time | `App\Support\RepClock` | All rep-local arithmetic: today, 08:00 as a UTC instant, the 30-day history window, ISO week start, the week-of-tomorrow rule, backfill weeks, DST-safe. Nothing else does timezone math. |
| Logging | `App\Support\JobLog` | The only logger jobs and commands use. Context keys come from an allowlist; string values must look like ids/dates/enums (no `@`, no spaces). `error()` is the alert level Datadog monitors key on. |
| Digest content | `App\Contracts\DigestBuilder` | Builds `DigestData` for a rep and local date from stored rows with a bounded number of queries. |
| Queue routing | `config('queue.queues')` | `sync`, `digest`, `maintenance`. Jobs set their queue from config, never from a literal. |
| Schedule | `routes/console.php` | `calendar:dispatch-sync` and `digest:dispatch-due` every minute, `meetings:purge` daily. All `onOneServer()->withoutOverlapping()` using the database cache lock store. |

## Where "today" is computed

Everything is stored in UTC. A rep has an IANA timezone (defaulting to the
tenant's). `RepClock` turns "now" into the rep's local date, and that date into
UTC bounds for queries. The digest for local date *D* contains meetings whose
`start_at` falls in `[D 00:00, D+1 00:00)` local, and counts history in
`[D − 30 days 00:00, D 00:00)` local. The daily sync on *D* fetches the ISO week
containing *D + 1*, so Monday always has fallback data from Sunday's fetch
(ADR-0002).

## Module ownership

| Module | Directories |
|---|---|
| Foundation (this document) | `app/Contracts`, `app/Data`, `app/Models`, `app/Tenancy`, `app/Support`, `app/Events`, `database/`, `tests/Support` |
| Tenant & rep commands | `app/Console/Commands/{Tenant,Rep}` |
| Calendar provider | `app/Services/Calendar`, `app/Providers/CalendarServiceProvider.php`, `database/fake_calendar` |
| Meeting sync | `app/Jobs/{SyncRepWeek,BackfillRep,PurgeOldMeetings}.php`, `app/Listeners`, `app/Services/Sync`, `app/Console/Commands/{Calendar,Meetings}` |
| Digest content | `app/Services/Digest/{EloquentDigestBuilder,LeadHistoryCounter,LinkedInUrl}.php`, `app/Providers/DigestServiceProvider.php`, `app/Mail`, `resources/views/mail` |
| Digest scheduling & send | `app/Jobs/SendDailyDigest.php`, `app/Services/Digest/{DueRepFinder,DataFreshness,DeliveryRecorder}.php`, `app/Console/Commands/Digest` |

## Plugging in the real provider

Implement `CalendarProvider`, map the provider's payload into the DTOs, and
register the class in `config/calendar.php`. Do not log calls, enforce caps or
handle timeouts yourself: the factory's decorator already does. Details in
`docs/calendar-provider.md`.
