# ADR-0005: No email on days without lead meetings

**Status**: accepted · **Date**: 2026-10-13

## Context
The scope says reps get an email at 08:00 daily with their leads. It does not
say what happens when a rep has no external meetings that day.

## Decision
Send nothing and record the delivery as `skipped_empty`.

## Consequences
- No noise in reps' inboxes; the email always means "you have leads today".
- Operators still see one `digest_deliveries` row per rep per day, so
  "no email" is distinguishable from "failed".
- Reversible by flipping one branch in `SendDailyDigest` to send an
  "no lead meetings today" template (PRD FR-13).
