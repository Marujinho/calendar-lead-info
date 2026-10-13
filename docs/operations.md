# Operations

Everything the service emits is a JSON log line on stdout, written through
`App\Support\JobLog`. Each line has an `event` key plus ids and numbers only
(`tenant_id`, `rep_id`, `local_date`, `week_start`, `status`, `outcome`,
`duration_ms`, `attempt`, `calls`, `cap`, `delay_seconds`, `exception`,
`queue`, `lead_count`, `meeting_count`, `reason`). No email, name, URL or
credential can appear: `JobLog` rejects them.

## Datadog log-based metrics

| Metric | Source log | Aggregation | Tags | PRD goal |
|---|---|---|---|---|
| `digest.send_delay_seconds` | `event:digest.sent` → field `delay_seconds` | p50 / p99 and max per day | `tenant_id` | G1 (≤ 300 s for 99.5 % of rep-days) |
| `calendar.provider.calls` | `event:calendar.provider.call` | count per day, split by `outcome` | `tenant_id`, `outcome` | G2 (≈ active reps per day in steady state) |
| `digest.deliveries` | `event:digest.sent`, `digest.skipped_empty`, `digest.failed_no_data`, `digest.failed_send` | count by `event` | `tenant_id` | G4 (duplicates are impossible by schema, so this is a sanity count: sent + skipped + failed = active reps) |
| `sync.attempts` | `event:sync.completed`, `sync.failed`, `sync.exhausted` | count by `event` | `tenant_id` | NFR-4 |

## Alerts (monitors on `status:error` logs)

| Event | Means | Check first | Fix |
|---|---|---|---|
| `digest.failed_no_data` | No successful sync covers today's ISO week for this rep, so no email was sent. | `rep_week_syncs` for the rep and `week_start`; `sync.failed` / `sync.exhausted` lines for the same `rep_id` in the last 24 h; `calendar.cap_reached` for the tenant. | Fix the provider issue, then `php artisan digest:simulate --at="<today> 08:05 <rep tz>" --tenant=<id>` after a manual `SyncRepWeek` dispatch for the rep, or let tomorrow's cycle recover. |
| `digest.failed_send` | Mail transport kept failing until 08:15 local, **or** a retry found the delivery already in `sending` (`reason=uncertain`). | `reason` field. For `uncertain`, check Mailpit/SES for a delivered message before resending. For transport errors, SES quota and credentials. | Resend by deleting that rep's `digest_deliveries` row for the date **only after confirming nothing was delivered**, then `digest:simulate`. |
| `calendar.cap_reached` | The tenant hit its daily provider-call cap (`cap_multiplier × active reps + 6 × reps created today`). Further calls today are rejected. | `calls` vs `cap` in the log; `provider_calls` outcomes for the day (a retry storm shows as many `failure`/`timeout` rows). | Usually a provider outage: nothing to do until it recovers; reps keep receiving digests from stored data with the stale notice. If the cap is genuinely too low (mass onboarding), raise `CALENDAR_CAP_MULTIPLIER` for the day. |
| `digest.send_delayed` | A digest was handed to the transport more than 300 s after its 08:00 local. | Queue depth on `digest`; whether the `digest` worker is running and separate from `sync`; scheduler health (`schedule:run` every minute). | Add `digest` workers; never let `sync` jobs run on the `digest` worker. |
| `sync.exhausted` | All retries for one rep's week failed before 07:45 local. | `exception` class in the log; provider status; `provider_calls` for the rep. | Transient: tomorrow's fetch covers it (the week-of-tomorrow rule keeps today's digest working from yesterday's data). Persistent: the real provider is broken for that tenant's credentials. |
| `purge.failed` | The nightly purge failed for one tenant (others continued). | `exception` class. | Re-run `php artisan meetings:purge`. |

## Routine checks

- Every morning during the pilot: `digest.send_delay_seconds` p99 < 300 s, `digest.failed_*` = 0, `calendar.provider.calls{outcome:success}` ≈ active reps.
- Weekly: `provider_calls` per rep per day in steady state ≤ 1.0 (G2); backfill calls only on days reps were added.

## Rollback

Stop the scheduler and the three workers. Data is additive and owned by this
service only; `php artisan migrate:rollback` is safe with respect to other
systems. Re-enabling later needs no backfill unless more than 30 days passed.
