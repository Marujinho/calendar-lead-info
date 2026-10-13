# ADR-0002: The daily sync fetches the ISO week containing the rep's local tomorrow

**Status**: accepted · **Date**: 2026-10-13

## Context
The provider returns a whole week per call and we allow one call per rep per
day. Fetching "the week containing today" leaves Monday without any stored
copy of its meetings if Monday's fetch fails, because Sunday's fetch covered
the previous week. The stale-data fallback (PRD FR-11) would then be empty on
the day it matters most.

## Decision
On local day *D* the sync fetches the ISO week containing *D + 1*. Monday to
Saturday that is the current week. Sunday it is next week, so by Monday
morning there is already a copy of Monday's meetings.

## Consequences
- Cost stays at one call per rep per day (G2).
- Sunday digests are built from Saturday's fetch and show the stale notice.
  Sunday B2B meetings are rare, so most Sunday deliveries are `skipped_empty`.
- Rejected alternative: an extra Sunday call for next week (≈1.14 calls/rep/day),
  which breaks the ≤ 1.0 target.
