# ADR-0003: Laravel database queue and cache instead of RabbitMQ / Redis in v1

**Status**: accepted · **Date**: 2026-10-13

## Context
The company stack lists RabbitMQ for communication between microservices.
This service talks to no other service: its jobs are internal
(sync, backfill, send, purge). Scheduler locks (`onOneServer`) need a cache
store that supports atomic locks.

## Decision
Use Laravel's `database` queue driver with three named queues
(`sync`, `digest`, `maintenance`) and the `database` cache store for locks.

## Consequences
- No new infrastructure to run or secure for v1.
- Queue isolation (NFR-1) comes from separate `queue:work` processes per queue
  name, not from separate brokers.
- Swapping to RabbitMQ or Redis later is a config change; job classes are
  unchanged because they only reference `config('queue.queues.*')`.
