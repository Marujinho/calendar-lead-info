# PRD: Daily Lead Briefing Email

**Source**: `SCOPE.MD` · **Type**: feature (greenfield) · **Status**: READY · **Date**: 2026-10-13 · **Revised**: 2026-10-13 after `/plan` (P1–P16, see §20)

> **Provider scope**: v1 makes no calls to the real Google Calendar API. The only `CalendarProvider` implementation built is `FakeCalendarProvider`. The real provider is a later drop‑in behind the same interface.

## 1. Overview
A Laravel service that, once per day per sales rep, pulls that rep's meetings from the calendar provider, picks out the external leads in today's meetings, and emails the rep at 08:00 in the rep's own timezone. For each lead the email shows full name, company, email, LinkedIn profile, and how many meetings anyone in the company has had with that lead in the last 30 days. The calendar source sits behind a Strategy interface. A fake implementation ships now, and the real one plugs in later without changes elsewhere. The system is built so it can be sold to other companies (tenant-ready), but v1 runs a single tenant.

## 2. Problem
Reps go into meetings without context. They have to look up who the lead is, where they work, and whether a colleague has already approached them. That takes prep time, and it misses repeat-approach signals: the same lead met by three reps this month looks like a new contact to each of them. The calendar provider charges per call, so a naive approach (one call per lead, or per rep per meeting) gets expensive as the rep count grows. There is no baseline data. This is a new capability.

## 3. Goals & Success Metrics
| Goal | Metric | Target | How it's measured |
|---|---|---|---|
| G1 On-time delivery | Share of due digests handed to the mail transport between 08:00:00 and 08:05:00 in the rep's local time | ≥ 99.5 % of rep-days | `digest_deliveries.sent_at` vs `scheduled_for`, plus a Datadog metric `digest.send_delay_seconds` |
| G2 Minimal API cost | Provider calls per active rep per day in steady state (not counting retries and the one-time backfill) | ≤ 1.0 | `provider_calls` count / active reps, plus Datadog metric `calendar.provider.calls` tagged by tenant |
| G3 Correct history counts | The 30-day meeting count in the email matches a reference computation over fixture data | 100 % in integration tests | Integration test suite (§17) |
| G4 Exactly-once delivery | Duplicate digests per rep per local date | 0 | Unique constraint on `(rep_id, local_date)` plus a test |

**Non-goals**: measuring rep prep time or conversion lift; real-time updates when meetings change during the day; a UI of any kind.

## 4. Users & Personas
- **Sales rep** (≤ 200 per company). Gets one email each morning with every external lead they meet today and how "warm" each lead already is. Today they research leads by hand or not at all.
- **Operator / admin** (engineering or sales ops). Registers tenants and reps through artisan commands and watches delivery health in Datadog. Today there is nothing to operate.
- **Future buyer company** (tenant). Gets an isolated instance of the same behaviour with its own reps, credentials and lead history.

## 5. User Stories
- **US-1**: As a sales rep, I want an email at 08:00 my local time listing today's meetings with external leads, so that I'm prepared before my first call.
- **US-2**: As a sales rep, I want each lead's full name, company, email and LinkedIn profile in that email, so that I don't have to look them up.
- **US-3**: As a sales rep, I want to see how many meetings anyone in my company had with that lead in the last 30 days, so that I know whether they've already been approached.
- **US-4**: As an operator, I want calendar API usage kept to the minimum needed, so that running cost scales linearly and predictably with the number of reps.
- **US-5**: As an operator, I want the 08:00 send to be unaffected by slow or failed data jobs, so that reps always get their email on time.
- **US-6**: As an operator, I want to add, deactivate and import reps and tenants from the CLI, so that I can onboard a company without a UI.
- **US-7**: As a developer, I want the calendar source behind an interface with a fake implementation, so that the real provider can be dropped in without touching the rest of the system.
- **US-8**: As a future buyer company, I want my reps and lead data isolated from other companies, so that the product can be sold safely.

## 6. User Flows
**Main flow (per rep, per local day)**
1. At 05:00 rep‑local time, the system fetches from the provider the ISO week that contains the rep's local **tomorrow** (one call) and stores the meetings and attendees. Monday to Saturday that is the current week; on Sunday it is next week, so every Monday already has a fallback copy of its meetings from Sunday's fetch.
2. At 08:00 local time, the system builds the digest from stored data. It includes today's (rep-local date) non-cancelled meetings with at least one external attendee. For each external lead it shows name, company, email, LinkedIn and the 30-day count.
3. The email goes out. The delivery is recorded as `sent`.

**Alternate flows**
- *No external meetings today*: no email is sent and the delivery is recorded as `skipped_empty`.
- *Same lead in several meetings today*: the lead appears under each meeting, and the count is the same for that lead everywhere in the email.
- *Meeting attended by several of our reps*: it counts once in the history (deduplicated by the provider's meeting id).
- *New rep*: a one-time backfill of the previous 30 days runs so counts are meaningful from day one.

**Error flows**
- *Fetch failed or is still running at 08:00*: the digest is built from the last stored data. A previous day's fetch of the same week already contains today's meetings (guaranteed by the week‑of‑tomorrow rule above). The email carries a one-line notice: "Meeting data last refreshed at {time}". "No data for today" means there is no successful `rep_week_syncs` row for the ISO week containing the rep's local today; then nothing is sent, the delivery is recorded as `failed_no_data`, and an alert fires. Sunday digests are always built from Saturday's fetch and carry the notice.
- *Mail transport error*: retried until 08:15 local. After that the delivery is marked `failed_send` and an alert fires. If a retry finds the delivery already in `sending` (a crash after the transport may have accepted the mail), it does not resend: it records `failed_send` with reason `uncertain` and alerts. A missed email is preferred to a duplicate.
- *Provider returns malformed data*: that rep's payload is rejected, other reps are unaffected, and an error is logged with tenant/rep ids only.

**States the rep sees**: an email with leads, an email with leads plus a stale-data notice, or no email (empty day or hard failure). There are no other user-facing states.

## 7. Functional Requirements
| ID | Requirement | Priority | Story | Acceptance criteria |
|---|---|---|---|---|
| FR-1 | Calendar data must be obtained only through a `CalendarProvider` Strategy interface. Providers are created per tenant by a `CalendarProviderFactory` (`forTenant(Tenant): CalendarProvider`) whose implementation class is chosen by config, so each tenant's credentials reach only that tenant's provider. The factory wraps every provider in a `MeteredCalendarProvider` decorator that owns call logging, the daily cap and outcome classification, so a real implementation only fetches. | Must | US-7 | Given `CALENDAR_PROVIDER=fake`, when the sync runs, then `FakeCalendarProvider` is used. Swapping the config to another class that implements the interface needs no change outside that class and its config entry (verified by a test registering a second stub implementation). The provider for tenant A is built with A's credentials only. |
| FR-2 | A `FakeCalendarProvider` must return deterministic, realistic week data (meetings, attendees with full name, company, email, LinkedIn URL, meeting status, attendee response status, shared meeting ids across reps). It supports modes `ok`, `fail`, `timeout`, `malformed` and a configurable latency for load tests. | Must | US-7 | Given a seed, when called twice for the same rep and week, then identical payloads come back. Fixtures include cross-rep shared meetings, cancelled and tentative meetings, internal-only meetings, a declined attendee, leads with no LinkedIn and a lead present in two tenants. A documented scenario for the three demo reps lists the expected 30‑day count per lead. |
| FR-3 | The system must make at most one provider call per active rep per local day in steady state. Each daily call fetches the ISO week containing the rep's local tomorrow. | Must | US-4 | Given 200 active reps and a normal day, when the daily cycle completes, then `provider_calls` for that tenant/day = 200 (plus any logged retries). On a Sunday the fetched week is the following week. |
| FR-4 | The daily sync starts at 05:00 rep‑local. Failed provider calls retry at most 2 times with backoff (+15 min, +60 min), and no attempt may start after the rep's 07:45 local time. | Must | US-4, US-5 | Given a provider that always fails, then ≤ 3 attempts are logged and none starts after 07:45 local. Given a first attempt at 07:40 that fails, no retry runs. |
| FR-5 | Fetched meetings and leads must be upserted idempotently (`INSERT … ON DUPLICATE KEY UPDATE`), keyed by tenant and provider meeting id, because two reps' syncs can race on a shared meeting. A meeting absent from rep R's week payload detaches R from it; the meeting is marked removed only when no rep remains attached, and un‑removed if it reappears. | Must | US-3 | Re-running a sync with the same payload changes no row counts. A shared meeting dropped from rep A's payload still appears for rep B. A meeting dropped from every attached rep's payload is excluded from digests and counts. |
| FR-6 | An attendee is a *lead* for a meeting when their email domain is not one of the tenant's internal domains, their email is not any rep of the tenant (active or inactive), and their response status is not `declined`. | Must | US-1 | Given internal domain `ourco.com`, rep `c@gmail.com`, attendees `a@ourco.com`, `c@gmail.com`, `b@client.com` and `d@client.com` (declined), then only `b@client.com` is a lead. Matching is case-insensitive. |
| FR-7 | The digest must list the rep's meetings whose start falls on the rep's local today, excluding cancelled/removed meetings and meetings with no leads, ordered by start time. | Must | US-1 | Given meetings yesterday, today (one cancelled, one internal-only, two with leads) and tomorrow, then exactly the two today-with-leads meetings appear, ordered by start. |
| FR-8 | For each lead the digest must show full name, company name, email and LinkedIn URL. Missing fields show "—". | Must | US-2 | Given a lead with no LinkedIn, then the LinkedIn cell shows "—" and the email still renders. |
| FR-9 | For each lead the digest must show the number of distinct meetings with status `confirmed` or `tentative`, not removed, where the lead did not decline, attended with any rep of the same tenant, with start in `[rep-local today − 30 days, rep-local today 00:00)`. Meetings older than 30 days are not counted. The count for every lead in a digest is computed in one grouped query. | Must | US-3 | Fixture: the lead had meetings 31 d ago, 30 d ago, 10 d ago (with 2 of our reps, same meeting id), 5 d ago (lead declined), 3 d ago (cancelled), and earlier today. Expected count = 2 (30 d and 10 d). The same lead in tenant B has 0 effect. |
| FR-10 | The digest must be sent at 08:00 in the rep's timezone (IANA zone stored per rep, defaulting to the tenant's zone). | Must | US-1, US-5 | Given reps in `America/Sao_Paulo` and `Europe/Lisbon`, then each digest's `scheduled_for` is 08:00 in its own zone. DST transition days are covered by a test. |
| FR-11 | Sending must not depend on any fetch job finishing. It uses whatever data is stored at send time. *Stale* = the `rep_week_syncs` row for the ISO week containing the rep's local today has `last_success_at` before today 00:00 local → the notice is added. *No data* = that row has no `last_success_at` at all → nothing is sent. | Must | US-5 | Given the fetch job is stuck or has failed, when 08:00 arrives, then the digest is sent on time with the notice. Given no successful sync covers today's week, then status = `failed_no_data` and an alert log is emitted. |
| FR-12 | Each rep must receive at most one digest per local date, even if the scheduler fires twice, runs on several servers, or a job crashes mid‑send. Mechanism: the dispatcher inserts the `digest_deliveries` row (`pending`, unique `(rep_id, local_date)`) before dispatching, and only the process whose insert succeeded dispatches; the job is `ShouldBeUnique`; the job moves the row to `sending` before calling the mail transport; a retry that finds `sending` does not resend. Statuses: `pending`, `sending`, `sent`, `skipped_empty`, `failed_no_data`, `failed_send`. | Must | US-1 | Dispatching the send for the same rep/date twice results in one email and one `digest_deliveries` row. A job that fails after `sending` was set is retried and ends as `failed_send` with reason `uncertain`, without a second email. |
| FR-13 | Days with no lead meetings send nothing and record `skipped_empty`. | Should | US-1 | Given a rep with only internal meetings today, then no mail and status `skipped_empty`. |
| FR-14 | When a rep is created, a one-time backfill must fetch the current ISO week plus every past ISO week intersecting `[first digest date − 30 days, today]` (≤ 6 calls) so counts are accurate from day one. `reps.backfilled_at` is set on completion. | Should | US-3, US-4 | Given a new rep, then the backfill makes ≤ 6 provider calls (6 on a Monday or Tuesday, fewer later in the week), and re-running it makes 0 calls once `backfilled_at` is set. |
| FR-15 | Artisan commands must create a tenant (name, internal domains, default timezone, provider credentials read from a hidden prompt or `--credentials-stdin`, never as an argument), and add (`rep:add {tenant} {email} {name} [--timezone]`), deactivate and CSV-import (`email,name,timezone`, timezone optional) reps. Rep creation fires a `RepCreated` event that triggers the backfill. Inactive reps are neither fetched nor emailed. | Must | US-6, US-8 | Each command has a test for success, a validation failure (bad email, unknown timezone, duplicate rep, unknown tenant, bad CSV header) and the effect of deactivation on the next cycle. Credentials never appear in `argv`. |
| FR-16 | All tenant data (reps, leads, meetings, pivots, syncs, deliveries, provider calls) carries `tenant_id` and is scoped by a `BelongsToTenant` global scope that reads a `TenantContext`. Querying a scoped model with no tenant in context throws `MissingTenantContextException`. Jobs set the context through middleware from their `tenant_id`; commands set it explicitly. Lead history counts never cross tenants. | Must | US-8 | Given the same lead email in tenants A and B, then A's count ignores B's meetings. A query without tenant context throws. An architecture test asserts every model except `Tenant` uses `BelongsToTenant`. |
| FR-17 | Stored meetings and their links older than 35 days must be purged daily, per tenant, along with leads that no longer have any meeting link. | Should | US-8 (data minimisation) | Given meetings 36 d and 34 d old, after the purge only the 34 d one remains. A lead whose only meeting was purged is deleted; a lead with a remaining meeting stays. |

## 8. Non-Functional Requirements
| ID | Category | Requirement | Acceptance criteria |
|---|---|---|---|
| NFR-1 | Reliability / timing | Digest dispatch runs on a dedicated queue that sync/backfill jobs cannot occupy. | Integration test: with the sync queue saturated by long-running jobs, digest jobs still run. In config, digest and sync queues have separate workers. |
| NFR-2 | Performance | Building one digest takes a bounded number of queries, independent of the number of leads or meetings (no N+1). | Test asserts the query count for a digest with 1 vs 20 leads is equal. Build time is < 500 ms for 20 meetings × 5 leads. |
| NFR-3 | Scale | The daily cycle supports 200 reps per tenant and many tenants. Fetch for 200 reps completes ≥ 60 min before 08:00 local, with the provider at 2 s/call and 5 parallel workers. | Load test with the fake provider at 2 s latency × 200 reps completes in < 90 s of wall time with 5 workers. |
| NFR-4 | Reliability | A failure for one rep (fetch, build or send) never blocks other reps. | Test: rep 2's provider payload is malformed, and reps 1 and 3 still get digests. |
| NFR-5 | Observability | Every job emits structured JSON logs (tenant_id, rep_id, local_date, status, duration_ms) to stdout for the Datadog agent, plus the metrics named in §3. | Log assertions in tests. No email address, name or LinkedIn appears in any log line (§12 A09). |
| NFR-6 | Accessibility | The email renders as both HTML and plain text, and is readable without images. | Mailable test asserts both parts contain every lead field. |
| NFR-7 | Maintainability | Pint passes, PHPStan (Larastan) passes at level 6, and every new function has a unit test (CLAUDE.md). | CI green. |

## 9. Out of Scope
- Real Google Calendar integration: the user will supply the real provider later (FR-1 is the seam).
- Enrichment from third parties (Apollo, Clearbit, CRM): the user specified that the provider returns all lead fields.
- Any web UI or HTTP API, self-serve tenant signup and billing: the tenancy decision is tenant-ready with one tenant and CLI onboarding.
- RabbitMQ integration: this is a self-contained service with no inter-service messaging in v1 (see Assumptions).
- Intra-day updates or re-sends when meetings change after 08:00.
- Unsubscribe and per-rep preferences beyond timezone.

## 10. Definition of Done
- [ ] Every Must requirement meets its acceptance criteria
- [ ] Unit and integration suites pass in CI against MySQL 8 (Docker)
- [ ] Every row of §12 has its test implemented and passing
- [ ] `php artisan schedule:run` in a local env with the fake provider and Mailpit produces correct digests for ≥ 3 reps in ≥ 2 timezones (§18)
- [ ] Work merged to `master` through the per-milestone branches in §19, each with its own PR and history
- [ ] README documents setup, commands, env vars and how to plug in a real `CalendarProvider`
- [ ] `docs/` contains architecture, data model, calendar provider, commands, sync, digest, operations pages and ADRs for the decisions in §20, each checked against the merged code
- [ ] No TODOs, stubbed error paths, disabled lint rules or `mixed`/`any` escapes

## 11. Technical Context
**Stack & areas affected**: The repo is greenfield. It holds only `SCOPE.MD`, `CLAUDE.md`, `.gitignore` (Laravel template) and `.claude/`, with one commit (`b49acc1`). No repo init was needed. Local toolchain: PHP 8.4.6, Composer 2.8.10, Docker 29.5, MySQL client 9.3. Target: Laravel 13 (current stable major, released 2026‑03) installed via `composer create-project laravel/laravel` (version pinned in `composer.lock`), PHP ≥ 8.3, MySQL 8.x, mail via SES in prod and Mailpit locally, Datadog through stdout JSON logs, GitHub Actions CI, deployed on AWS.

**Conventions** (all PROPOSED, no existing code to mirror):
- Laravel default layout. Contracts in `app/Contracts/` (`CalendarProvider`, `CalendarProviderFactory`, `DigestBuilder`). Implementations in `app/Services/Calendar/` (`FakeCalendarProvider`, `MeteredCalendarProvider`, `ConfigCalendarProviderFactory`, `ArrayPayloadMapper`). Bindings live in domain providers `CalendarServiceProvider` and `DigestServiceProvider` (registered in `bootstrap/providers.php`); `AppServiceProvider` is left alone. Provider class chosen by `config/calendar.php` → `CALENDAR_PROVIDER`.
- Provider payloads are mapped into immutable readonly DTOs (`app/Data/…`) at the boundary. Nothing past the provider sees raw arrays. Status enums live in `app/Data/Enums`; no string literals for statuses elsewhere.
- Jobs in `app/Jobs` (`SyncRepWeek`, `BackfillRep`, `SendDailyDigest`, `PurgeOldMeetings`) on queues `sync`, `digest`, `maintenance`. Commands in `app/Console/Commands/{Tenant,Rep,Calendar,Digest,Meetings}`. Mailable `app/Mail/DailyLeadBriefing.php` with a Blade markdown view. Schedule entries in `routes/console.php`, all `onOneServer()->withoutOverlapping()`.
- Domain exceptions in `app/Exceptions` (`ProviderUnavailableException`, `InvalidProviderPayloadException`, `ProviderCallCapReachedException`, `MissingTenantContextException`). Jobs record a status and rethrow for queue retry. Nothing is swallowed.
- Every tenant-owned model uses the `BelongsToTenant` global scope backed by `app/Tenancy/TenantContext`. Jobs apply the `SetTenantContext` middleware.
- All timezone math goes through `app/Support/RepClock` (rep‑local today, UTC bounds, 30‑day window, ISO week start, week‑of‑tomorrow). All structured logging goes through `app/Support/JobLog`, which only accepts an allowlist of id/metric keys and throws on anything else.
- Tests use PHPUnit (Laravel default): `tests/Unit`, and `tests/Integration` (MySQL, RefreshDatabase, `Mail::fake`, `Carbon::setTestNow`), with shared helpers in `tests/Support` (`IntegrationTestCase`, `StubCalendarProvider`, `StubCalendarProviderFactory`). Formatting with Pint (Laravel preset). Static analysis with Larastan level 6.
- Times are stored in UTC. Rep-local dates are computed in PHP with the rep's IANA zone.
- Documentation in `docs/` (architecture, data-model, calendar-provider, commands, sync, digest, operations, `decisions/ADR-*`) and `README.md`, reviewed like code.

**Data & contracts** (PROPOSED):
- Provider contract: `CalendarProvider::fetchWeek(string $repEmail, CarbonImmutable $anyDayInWeek): WeekSchedule`; `CalendarProviderFactory::forTenant(Tenant): CalendarProvider`; `DigestBuilder::build(Rep, CarbonImmutable $localDate): DigestData`. `WeekSchedule` holds `MeetingData{externalId, title, startAt, endAt, status: confirmed|tentative|cancelled, attendees: list<AttendeeData{email, fullName, companyName, ?linkedinUrl, responseStatus: needsAction|declined|tentative|accepted (default accepted)}>}`.
- Tables (every one with `tenant_id` FK + index): `tenants` (name, internal_domains json, default_timezone, provider_credentials encrypted nullable), `reps` (email unique per tenant, name, timezone, active, backfilled_at), `leads` (email unique per tenant, full_name, company_name, linkedin_url), `meetings` (external_id unique per tenant, title, start_at, end_at, status, removed_at; index(tenant_id, start_at)), `meeting_rep` (meeting_id, rep_id unique), `meeting_lead` (meeting_id, lead_id unique, response_status), `rep_week_syncs` (rep_id, week_start unique per rep, last_success_at, last_attempt_at, attempts, last_error_class), `digest_deliveries` (rep_id, local_date unique per rep, scheduled_for UTC, status, failure_reason, sent_at, data_refreshed_at, attempts), `provider_calls` (rep_id, week_start, purpose sync|backfill, outcome success|failure|timeout|rejected_cap, duration_ms, called_at).
- These are new migrations only. There is nothing to stay backwards-compatible with.

**Constraints**: send never waits on fetch (FR-11, NFR-1). Lead history is computed from local storage, never with extra provider calls (FR-3). Every tenant query is scoped (FR-16). No new HTTP routes.

**Dependencies**: none blocking. The real calendar provider comes later from the user. SES credentials are needed in prod only.

## 12. Security (OWASP Top 10:2025)

| # | Category | Applies? | Requirement | How it's tested |
|---|---|---|---|---|
| A01 | Broken Access Control | Yes | A rep's digest contains only meetings on that rep's own calendar. History counts and lead records are tenant-scoped through a global scope. There are no unscoped queries on `leads`/`meetings`/`reps`. | Integration: two tenants share a lead email, and the counts and digest content don't leak. Rep A's digest never contains rep B's meetings unless A attended. Unit test: the model global scope is applied. |
| A02 | Security Misconfiguration | Yes | `APP_DEBUG=false` and `APP_ENV=production` in prod. No default or sample credentials in the repo. `.env*` stays gitignored (already true, `.gitignore:19`). The fake provider cannot be selected when `APP_ENV=production` unless explicitly overridden. | Boot test: production env + `CALENDAR_PROVIDER=fake` throws unless `CALENDAR_ALLOW_FAKE=true`. CI grep for committed `.env`. |
| A03 | Software Supply Chain Failures | Yes | `composer.lock` committed. No runtime deps beyond the Laravel skeleton and the SES mail transport. Dev deps: Larastan, Pint. `composer audit` runs in CI and fails on advisories. GitHub Actions pinned to commit SHAs. | CI job `composer audit`. Review of `composer.json` diff per PR. |
| A04 | Cryptographic Failures | Yes | Provider credentials stored per tenant with Laravel's `encrypted` cast (APP_KEY). Mail transport uses TLS. APP_KEY comes from the secrets manager, never the repo. | Unit test: the raw DB column ≠ the plaintext credential. Config test: the mail scheme is TLS in production config. |
| A05 | Injection | Yes | All DB access through Eloquent/query builder bindings. Lead fields from the provider are rendered only with escaped Blade `{{ }}`. A LinkedIn URL is rendered as a link only if it's `https` and the host is `linkedin.com` or `*.linkedin.com`, and is otherwise shown as "—". No provider data goes into mail headers (fixed subject template plus the date only). | Unit tests: a lead name containing `<script>` renders escaped. `javascript:` and `https://evil.com` LinkedIn URLs aren't linked. A name containing CRLF doesn't alter headers. |
| A06 | Insecure Design | Yes | Provider calls are budgeted (FR-3/FR-4), with a hard per-tenant per-UTC-day cap of `3 × active reps + 6 × reps created that day` enforced by the `MeteredCalendarProvider` decorator; the cap halts further calls (outcome `rejected_cap`) and emits `calendar.cap_reached`. The multiplier is 3 so a full-outage retry storm (3 attempts per rep) still gives every rep its attempts. Sending is exactly-once (FR-12). Sends are isolated from fetches (NFR-1). | Integration tests for the cap, idempotency and queue isolation. |
| A07 | Authentication Failures | Partially | No user-facing authentication exists (no HTTP surface, CLI only). Provider authentication credentials are per tenant, loaded only for that tenant's calls, and never shared across tenants. | Unit test: the provider for tenant A is built with A's credentials only. Route list test: no web/api routes beyond the framework health check. |
| A08 | Software or Data Integrity Failures | Yes | Provider payloads are validated into DTOs (required fields, email format, ISO timestamps, enum status). Invalid meetings are rejected and counted. Nothing from the provider is passed to `unserialize`. Queue payloads carry ids only. | Unit tests per invalid-field case. Test: a malformed attendee rejects that meeting without aborting the rep's sync. |
| A09 | Security Logging and Alerting Failures | Yes | Structured logs for each sync/send/purge outcome, with ids only. Alerts (error-level log plus a Datadog monitor) for `failed_no_data`, `failed_send`, the cap being hit, and send delay > 5 min. No emails, names, LinkedIn URLs or credentials in logs or exception messages. | Test: the log capture across a full cycle contains no fixture PII strings. Test: each alert condition emits an error-level log with the expected event key. |
| A10 | Mishandling of Exceptional Conditions | Yes | Provider timeouts (≤ 10 s) and errors are handled per rep with bounded retries. A stale-data fallback applies at send time. Failures are recorded with a status and never silently dropped. Mail failures are retried until 08:15 local and then marked failed. | Integration: provider timeout, provider exception, malformed payload, mail transport exception. Each yields the documented status and does not affect other reps. |

**Existing controls at risk**: None identified (greenfield). The `.gitignore` already excludes `.env`, `auth.json` and `/vendor/`, and that must stay so.
**Secrets / PII touched**: Lead PII (name, company, email, LinkedIn) and rep emails/names are stored in MySQL, rendered into emails sent to the owning rep only, purged after 35 days (FR-17), and never logged. Secrets (provider credentials, APP_KEY, SES keys): provider credentials are encrypted in the DB, and the rest come from env injected by AWS secrets management.

## 13. Do / Don't
- Do: obtain every provider through `CalendarProviderFactory::forTenant()`. The `MeteredCalendarProvider` decorator it applies logs each call to `provider_calls` and enforces the cap; implementations never do this themselves.
- Do: compute "today" and the 30-day window in the rep's timezone through `RepClock`, and store UTC.
- Do: dedupe history by provider meeting id across reps.
- Do: log only through `JobLog` with ids and metrics; it throws on any other key.
- Don't: call the provider while building or sending a digest. That breaks FR-3 and lets a slow API delay the 08:00 send.
- Don't: put digest and sync jobs on the same queue/worker. A long sync would delay sends.
- Don't: query tenant tables with `withoutGlobalScopes()`, anywhere. The purge iterates tenants and sets the context. Bypassing the scope leaks data across tenants.
- Don't: log attendee emails "for debugging". That's PII in Datadog.
- Don't: edit a migration, contract, DTO, config file, `routes/console.php` or `bootstrap/*` inside a Wave C feature branch (§19). Stop and report; those land as a separate small change everyone rebases onto.

## 14. Risks & Caveats
| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| The real provider contract differs from the assumed one (week param, shared meeting ids, status field) | High | Medium | DTO boundary: only the adapter changes. Assumptions listed in §15 so the real implementation knows what to map. |
| Monday mornings: today's meetings are in a new week no prior fetch has covered, so the stale-data fallback is empty if the Monday fetch fails | Medium | High | Decided in `/plan` (§20 P3): every daily fetch targets the ISO week containing the rep's local tomorrow, so Sunday's call fetches next week. Monday always has a fallback at no extra cost. Sunday digests are built from Saturday's data and show the stale notice. |
| Pilot cannot run in production until the real provider exists (A02 blocks the fake in prod) | Certain | Low | Rollout waits for the real provider; until then the service runs in staging with `CALENDAR_ALLOW_FAKE=true`. |
| Scheduler fires late or a server is down at 08:00 | Low | High | Scheduler runs every minute with `onOneServer` (needs a shared cache lock store). Sends due in the last 15 min are caught up. Datadog monitor on send delay. |
| DST gaps/overlaps make 08:00 ambiguous | Low | Low | 08:00 is never in a DST gap for real zones. Covered by a test anyway. |
| Meetings booked after the morning fetch are missing from the email | Medium | Low | Accepted: v1 has one fetch per day by design (cost). |
| History is incomplete if a rep is added without backfill | Medium | Medium | FR-14 backfill runs automatically on rep creation. |

## 15. Assumptions
| Assumption | Basis | If wrong |
|---|---|---|
| The provider returns, per attendee, full name, company, email and LinkedIn | User answer: "assume the calendar api will bring all the data" | Add an enrichment Strategy. The DTO fields stay the same. |
| The provider takes the rep email plus a date and returns that whole week, past and future days included | SCOPE.MD caveat | The adapter calls per day/range instead. The FR-3 call budget is re-evaluated. |
| Each meeting has a stable id shared across all attendees' calendars | Typical of calendar APIs (iCal UID) | Dedupe by (lead email, start time). Small over/under-count risk. |
| Meetings have a status (`confirmed`, `tentative`, `cancelled`) and cancelled ones are still returned with that status; attendees carry a response status (`needsAction`, `declined`, `tentative`, `accepted`) | Google Calendar `events` resource | Treat a missing meeting as removed (FR-5 already does this). If no response status exists, the adapter defaults to `accepted`. |
| "Last 30 days, if more than that do not add it" means: count only meetings in the 30 days before today, excluding today | SCOPE.MD wording | Change the window bounds in one query. Tests pin it. |
| Count = meetings of the lead with *any* rep in the tenant, including the receiving rep | "with someone from our company" | Exclude the rep's own meetings with a single condition. |
| Week = Monday–Sunday | ISO convention | Adapter-only change. |
| Rep timezone defaults to the tenant default and can be overridden per rep | User chose per-rep timezone | — |
| No email is sent on days without lead meetings | Avoids noise. Not stated in scope | Send an "no lead meetings today" email instead (FR-13 flip). |
| Job queue uses Laravel's `database` driver on MySQL. RabbitMQ is not used in v1 | The scope lists RabbitMQ for *inter-service* messaging, and this service talks to none. Avoids a new dependency | Swap the queue connection to a RabbitMQ driver via config. Jobs unchanged. |
| Mail via AWS SES in prod, Mailpit locally | Infra is AWS | Change the mail transport config only. |
| Datadog ingests stdout JSON logs through the agent. No APM tracer in v1 | Minimal dependencies | Add `dd-trace-php` later without code changes. |
| Tenant model: tenant-ready, one tenant, CLI onboarding | User answer | — |
| Rep roster lives in a DB table managed by artisan commands | User answer | — |

## 16. Open Questions
None. The user answered enrichment, timezone, tenancy and roster. Everything else is in §15.

## 17. Tests
**Unit** (`tests/Unit`)
- FakeCalendarProvider determinism and fixture coverage (FR-2). Container binding by config, plus the second-stub swap (FR-1).
- Payload → DTO validation: each required field missing or invalid, bad email, bad timestamp, unknown status (A08).
- Lead classification: internal domain, the rep themself, case-insensitivity (FR-6).
- Rep-local "today" and the 30-day window across timezones and DST dates (FR-9, FR-10).
- History count edge cases: exactly 30 d (in), 30 d + 1 s (out), today (out), cancelled (out), same meeting on 2 reps (1), other tenant (0) (FR-9, FR-16, A01).
- Mailable rendering: all fields, "—" for missing, HTML plus text parts, XSS/URL/CRLF cases (FR-8, NFR-6, A05).
- Encrypted credential cast (A04). Fake provider blocked in production (A02).
- Retry/backoff limits and the 07:45 cutoff (FR-4).

**Integration** (`tests/Integration`, MySQL in Docker)
- Full daily cycle for 3 reps / 2 timezones / 2 tenants: correct emails, counts and statuses (FR-7, FR-9, FR-10, FR-16).
- Provider call counting: steady state = 1/rep/day, Sunday fetches next week, backfill ≤ 6, re-run backfill = 0, cap enforcement (FR-3, FR-14, A06).
- Idempotent upsert, detach/removed_at rule for shared meetings, concurrent syncs of a shared meeting (FR-5).
- Double dispatch → one email; crash after `sending` → `failed_send`/`uncertain` and no second email (FR-12). Empty day → `skipped_empty` (FR-13).
- Lead classification against internal domains, all tenant reps and declined status (FR-6). Purge removes orphan leads (FR-17).
- Fetch failure/stuck → on-time send with stale notice. No data → `failed_no_data` plus alert (FR-11, A10).
- Queue isolation under saturated sync queue (NFR-1). Per-rep failure isolation (NFR-4).
- Query count independent of lead count (NFR-2).
- Purge job (FR-17). Artisan commands: success, validation errors, deactivation (FR-15).
- Log capture contains no PII, and alert events are present (NFR-5, A09). No web/api routes (A07).

**Load** (opt-in group `--group=load`): 200 reps, provider latency 2 s, 5 workers, < 90 s (NFR-3).

**Fixtures needed (don't exist yet)**: fake-provider seed dataset, tenant/rep factories, a `docker-compose.yml` with MySQL 8 and Mailpit for integration tests.

## 18. Verification
```bash
composer install
cp .env.example .env && php artisan key:generate
docker compose up -d mysql mailpit
php artisan migrate
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test --testsuite=Unit
php artisan test --testsuite=Integration
composer audit
```
End-to-end (local, fake provider):
1. `php artisan tenant:create "Acme" --domains=acme.test --timezone=America/Sao_Paulo` (credentials prompted, hidden)
2. `php artisan rep:add 1 ana@acme.test "Ana" --timezone=America/Sao_Paulo`, `rep:add 1 rui@acme.test "Rui" --timezone=Europe/Lisbon`, `rep:add 1 lee@acme.test "Lee"`. Confirm the backfill made ≤ 6 calls each (`provider_calls`).
3. `php artisan digest:simulate --at="2026-10-14 08:00 Europe/Lisbon"` runs both dispatchers with a frozen clock and processes the jobs inline. Confirm only rui's email appears in Mailpit. Repeat with `--at="2026-10-14 08:00 America/Sao_Paulo"` and confirm ana's and lee's.
4. Open the emails in Mailpit: verify the fields and that the counts match the expected values table in `docs/calendar-provider.md`.
5. `CALENDAR_FAKE_MODE=fail php artisan digest:simulate --at="2026-10-15 08:00 Europe/Lisbon"` and confirm the stale-data notice and on-time send.

## 19. Release Plan
- **Repository & base branch** (user decision 2026‑10‑13): GitHub repo created with `gh`; `master` pushed; `development` created from it. Every wave branch opens a PR into `development`; one final PR `development → master` after Wave D. Each PR passes `/review` (Pint, Larastan, tests, OWASP) first.
- **Branches, in waves** (so history traces decisions, and so independent work runs in parallel without touching the same files):
  - **Wave A** (serial): `chore/laravel-bootstrap`: Laravel 13 skeleton, Pint, Larastan L6, PHPUnit suites, docker-compose (MySQL 8, Mailpit), CI, all `config/*` and `.env.example` keys.
  - **Wave B** (serial, the keystone): `feature/domain-schema-and-contracts`: every migration, model, factory, `BelongsToTenant` + `TenantContext` + job middleware, contracts, DTOs, enums, exceptions, `RepClock`, `JobLog`, `RepCreated` event, empty `CalendarServiceProvider` / `DigestServiceProvider`, pre‑registered schedule entries in `routes/console.php` (`calendar:dispatch-sync`, `digest:dispatch-due`, `meetings:purge`), `tests/Support` scaffolding, `docs/architecture.md`, `docs/data-model.md`, first ADRs.
  - **Wave C** (five branches in parallel, disjoint file ownership, none may edit anything from A or B):
    1. `feature/tenant-rep-commands` (FR-15): `app/Console/Commands/{Tenant,Rep}`, `docs/commands.md`
    2. `feature/fake-calendar-provider` (FR-1, FR-2, FR-3 metering, A02, A06, A07, A08): `app/Services/Calendar/**`, `CalendarServiceProvider`, `database/fake_calendar`, `docs/calendar-provider.md`
    3. `feature/meeting-sync` (FR-4, FR-5, FR-6, FR-14, FR-17): `SyncRepWeek`, `BackfillRep`, `PurgeOldMeetings`, listener, `app/Services/Sync/**`, `app/Console/Commands/{Calendar,Meetings}`, `docs/sync.md`
    4. `feature/digest-builder-and-mail` (FR-7, FR-8, FR-9, NFR-2, NFR-6, A05): `EloquentDigestBuilder`, `LeadHistoryCounter`, `LinkedInUrl`, `DigestServiceProvider`, mailable + views, `docs/digest-content.md`
    5. `feature/digest-scheduling-and-send` (FR-10–FR-13, A09, A10 mail side): `SendDailyDigest`, `DueRepFinder`, `DataFreshness`, `DeliveryRecorder`, `app/Console/Commands/Digest` (incl. `digest:simulate`), `docs/digest-delivery.md`
    Merge order: 2, 1, 3, 4, 5. Full suite after each merge.
  - **Wave D** (serial): `feature/end-to-end-and-observability`: cross‑area integration tests (full cycle, isolation, queue isolation, PII‑free logs, alert events, no routes), load group, `README.md`, `docs/operations.md`, merged `docs/digest.md`, §18 run against Mailpit.
- **Milestones**: waves in the order above. Nothing ships to prod before Wave D is merged and the real provider exists.
- **Migrations / ordering**: all migrations are new and run before workers start. Create a tenant and reps after migrating, and backfill before the first 08:00.
- **Config / secrets**: `CALENDAR_PROVIDER` (default `fake`), `CALENDAR_ALLOW_FAKE` (default false), `CALENDAR_PROVIDER_TIMEOUT` (10), `CALENDAR_CAP_MULTIPLIER` (3), `CALENDAR_FAKE_MODE` (`ok`), `CALENDAR_FAKE_LATENCY_MS` (0), `DIGEST_SEND_HOUR` (8), `DIGEST_SEND_WINDOW_MINUTES` (15), `DIGEST_SYNC_HOUR` (5), `DIGEST_SYNC_CUTOFF` (`07:45`), `DIGEST_RETENTION_DAYS` (35), `MAIL_MAILER=ses` + AWS SES creds, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `APP_KEY`. Prod values are set by whoever owns AWS secrets management.
- **Feature flag**: none. A rep's `active` flag acts as the per-rep kill switch.
- **Rollout**: staging with the fake provider until the real `CalendarProvider` is delivered; then one tenant with 3–5 pilot reps for one week, then all reps.
- **Rollback**: stop the scheduler and workers. The data is additive and owned by this service only, so the migrations can be rolled back without affecting other systems.
- **Post-launch**: a Datadog dashboard for G1 (send delay), G2 (calls/rep/day) and G4 (duplicate count, expected 0), reviewed daily during the pilot week and then weekly.

## 20. Revision log (from `/plan`, 2026-10-13)
Changes applied to the first draft after planning review. Each is also an ADR candidate in `docs/decisions/`.

| # | Where | Change |
|---|---|---|
| P1 | FR-1, §11, A07 | Per-tenant `CalendarProviderFactory::forTenant()` instead of a single container binding, so credentials never cross tenants. |
| P2 | FR-1, §13 | `MeteredCalendarProvider` decorator owns call logging, cap and outcome classification; implementations only fetch. |
| P3 | §6, FR-3, §14 | Daily fetch targets the ISO week containing the rep's local tomorrow; closes the Monday gap at 1 call/rep/day. Sunday digests show the stale notice. |
| P4 | FR-14 | Backfill budget ≤ 6 (current week + up to 5 past ISO weeks). |
| P5 | FR-4 | Sync starts 05:00 local, backoff +15/+60 min, hard stop 07:45; "≤ 3 attempts". |
| P6 | A06 | Cap = 3 × active reps + 6 × reps created today, per tenant per UTC day. |
| P7 | FR-6, §19 | Lead classification moved into the sync branch; excludes every tenant rep (any domain) and declined attendees. |
| P8 | §11, §15 | DTO carries meeting status `confirmed\|tentative\|cancelled` and attendee `responseStatus`. |
| P9 | FR-5 | Shared-meeting removal rule: detach the rep; `removed_at` only when no rep remains; `upsert()` for races. |
| P10 | FR-12, §6 | Delivery state machine with `sending`; a retry that finds `sending` never resends. |
| P11 | FR-11 | "Stale" and "no data" defined on `rep_week_syncs` for the week containing today. |
| P12 | FR-16, §13 | `TenantContext` + scope that throws when unset; pivots carry `tenant_id`; architecture test; no `withoutGlobalScopes()` anywhere. |
| P13 | FR-17 | Purge also deletes orphan leads. |
| P14 | FR-15, §18 | Credentials via hidden prompt/stdin; `rep:add` takes a name; CSV header defined. |
| P15 | §14, §19 | Rollout waits for the real provider; staging runs the fake. |
| P16 | §19 | GitHub + `development` base; branches re-cut into waves A–D with disjoint ownership; documentation added to DoD. |
