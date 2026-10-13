# Calendar Lead Info

Every sales rep gets one email at 08:00 in their own timezone listing the external leads they meet that day: full name, company, email, LinkedIn, and how many times anyone in the company has met that lead in the last 30 days. The service fetches each rep's calendar week once a day through a `CalendarProvider` Strategy, stores it in MySQL, and builds the email from stored data only, so a slow or failing calendar API can never delay the 08:00 send. It is tenant-ready and operated from the CLI. There is no web UI.

**v1 uses a deterministic fake calendar provider.** The real provider is a later drop-in behind the same interface; see [docs/calendar-provider.md](docs/calendar-provider.md).

## Local setup

Requirements: PHP 8.3+, Composer 2, Docker.

```bash
composer install
cp .env.example .env && php artisan key:generate
docker compose up -d --wait            # MySQL 8.4 on 127.0.0.1:3307, Mailpit UI on http://localhost:8025
php artisan migrate
```

## Running it

Three long-running processes plus the scheduler:

```bash
php artisan schedule:work                         # or a cron entry: * * * * * php artisan schedule:run
php artisan queue:work --queue=sync               # calendar fetches and backfills
php artisan queue:work --queue=digest             # 08:00 sends (never shares a worker with sync)
php artisan queue:work --queue=maintenance        # nightly purge
```

The scheduler dispatches per rep, in the rep's timezone: `calendar:dispatch-sync` (05:00–07:45 window) and `digest:dispatch-due` (08:00–08:15 window) run every minute; `meetings:purge` runs daily at 03:00. All three use `onOneServer()` with the database cache lock store, so they are safe on several servers.

## Commands

| Command | What it does |
|---|---|
| `tenant:create "Acme" --domains=acme.test --timezone=America/Sao_Paulo` | Creates a tenant. Provider credentials are read from a hidden prompt (or `--credentials-stdin`), never from arguments. |
| `rep:add 1 ana@acme.test "Ana" --timezone=America/Sao_Paulo` | Adds a rep and triggers the one-time 30-day backfill (≤ 6 provider calls). |
| `rep:import 1 reps.csv` | Imports reps from a CSV with header `email,name,timezone`. |
| `rep:deactivate 1 ana@acme.test` | Per-rep kill switch: no more fetches or emails. |
| `digest:simulate --at="2026-10-14 08:00 Europe/Lisbon"` | Freezes the clock, runs the dispatchers and processes the jobs inline. Check Mailpit. |
| `calendar:dispatch-sync`, `digest:dispatch-due`, `meetings:purge` | The scheduled entry points (normally run by the scheduler). |

Full reference: [docs/commands.md](docs/commands.md).

## Configuration

| Env | Default | Meaning |
|---|---|---|
| `CALENDAR_PROVIDER` | `fake` | Provider implementation (`fake` or a class implementing `App\Contracts\CalendarProvider`). |
| `CALENDAR_ALLOW_FAKE` | `false` | Required to run the fake in `APP_ENV=production`. |
| `CALENDAR_PROVIDER_TIMEOUT` | `10` | Seconds per provider call. |
| `CALENDAR_CAP_MULTIPLIER` | `3` | Daily per-tenant call cap = multiplier × active reps + 6 × reps created today. |
| `CALENDAR_FAKE_MODE` | `ok` | `ok`, `fail`, `timeout`, `malformed` for local runs and tests. |
| `CALENDAR_FAKE_LATENCY_MS` | `0` | Artificial latency for the load test. |
| `DIGEST_SEND_HOUR` / `DIGEST_SEND_WINDOW_MINUTES` | `8` / `15` | Local send hour and how long sends may retry. |
| `DIGEST_SYNC_HOUR` / `DIGEST_SYNC_CUTOFF` | `5` / `07:45` | Local sync start and the hard stop for retries. |
| `DIGEST_RETENTION_DAYS` | `35` | Meetings and orphan leads older than this are purged. |
| `QUEUE_CONNECTION` / `CACHE_STORE` | `database` | No Redis or RabbitMQ needed in v1. |
| `MAIL_MAILER` | `smtp` (Mailpit) locally, `ses` in prod | Plus `AWS_*` keys in prod. |
| `LOG_CHANNEL` | `stdout` | One JSON object per line for the Datadog agent. |

## Development

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G     # Larastan level 6
php artisan test --testsuite=Unit
php artisan test --testsuite=Integration          # needs the docker MySQL
php artisan test --group=load                     # opt-in: 200 reps, 2 s fake latency, 5 workers
composer audit
```

Tests generate a throwaway `APP_KEY` per process; nothing secret is committed. CI runs the same checks plus a guard against committed `.env` files.

## Documentation

- [docs/architecture.md](docs/architecture.md): boundaries and the daily cycle
- [docs/data-model.md](docs/data-model.md): tables, the shared-meeting removal rule, the delivery state machine
- [docs/calendar-provider.md](docs/calendar-provider.md): plugging in the real provider, fake modes, demo scenario
- [docs/commands.md](docs/commands.md), [docs/sync.md](docs/sync.md), [docs/digest.md](docs/digest.md)
- [docs/operations.md](docs/operations.md): Datadog metrics, alerts and runbooks
- [docs/decisions/](docs/decisions/): ADRs
- [daily-lead-briefing-email.prd.md](daily-lead-briefing-email.prd.md): the requirements

## Delivery status (2026-10-13)

Delivered and green in CI on `development`:

- Laravel 13 bootstrap, Pint, Larastan level 6, PHPUnit suites, Docker (MySQL 8.4, Mailpit), GitHub Actions.
- Domain schema (9 tenant-scoped tables), models and factories, `TenantContext` with a scope that throws when unset, the `CalendarProvider` / `CalendarProviderFactory` / `DigestBuilder` / `DigestMailer` contracts, DTOs and enums, `RepClock`, `JobLog`, the three schedule entries, 64 tests, architecture and data-model docs, ADR-0001..0006.

In progress on five feature branches, merged as each passes review and CI (in this order): `feature/fake-calendar-provider`, `feature/tenant-rep-commands`, `feature/meeting-sync`, `feature/digest-builder-and-mail`, `feature/digest-scheduling-and-send`.

### Tests deferred to a follow-up

Time-boxed delivery: the following tests are written as drafts (see `tests/Integration/EndToEnd` once merged) or listed here, and must be completed before production:

- End-to-end daily cycle across 3 reps, 2 timezones and 2 tenants (PRD §17): drafted, runs only once all five feature branches are merged.
- Provider call budget on `provider_calls` (steady state 1/rep/day, backfill ≤ 6, daily cap) with the real fake provider.
- Queue isolation under a saturated `sync` queue (NFR-1), beyond the queue-name assertions.
- Load test (`--group=load`): 200 reps, 2 s fake latency, 5 workers, < 90 s (NFR-3).
- PII-free log capture over a full cycle and alert-event presence (NFR-5, A09): drafted.
- Per-module edge cases each feature branch lists under "Tests deferred" in its PR description.
