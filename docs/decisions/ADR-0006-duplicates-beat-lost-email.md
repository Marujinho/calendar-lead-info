# ADR-0006: In the send path, a lost email is preferred to a duplicate

**Status**: accepted · **Date**: 2026-10-13

## Context
A job can crash after the mail transport accepted the message but before the
delivery row is marked `sent`. A naive retry would send the digest twice,
violating the exactly-once goal (G4 = 0 duplicates).

## Decision
- The dispatcher inserts the `digest_deliveries` row (`pending`, unique on
  rep + local date) before dispatching, and only the inserter dispatches.
- `SendDailyDigest` is `ShouldBeUnique` and moves the row to `sending`
  immediately before calling the transport.
- A retry that finds the row already in `sending` does **not** resend. It
  records `failed_send` with reason `uncertain` and emits the
  `digest.failed_send` alert so an operator can decide.

## Consequences
- Duplicate digests are impossible by construction; the window for a lost
  email is the few milliseconds between transport acceptance and the `sent`
  update, and it is always visible as an alert.
- Operators have a runbook entry (docs/operations.md) for `uncertain`.
