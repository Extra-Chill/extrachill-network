# Booking invariants journey — findings (extrachill-network#292)

32 backend invariant assertions, **32 passed**, 0 open findings, 10 skipped
as documented rig-environment limitations. Full machine-readable result:
`result.json`.

## Skips (not product defects)

| Case | Reason |
| --- | --- |
| `concurrent-booking-cas-single-winner` | Requires a real MySQL server for a raw two-connection race; this rig's default database is SQLite. |
| `event-conversion-succeeds`, `event-conversion-idempotent`, `event-reschedule-succeeds`, `linked-cancellation-succeeds`, `cancelled-event-and-timezone-align`, `terminal-booking-message-rejected`, `activity-ledger-terminal-markers`, `event-source-link-unique` | Canonical event conversion returns `booking_event_ability_unavailable` (503, retryable): the upsert serializes on the MySQL-only `GET_LOCK` primitive this SQLite-backed runtime does not provide. Everything downstream of a real converted event inherits the skip rather than being silently omitted or misreported as a failure. |
| `message-exact-retry` | `send-booking-message` returns `booking_message_delivery_uncertain` in this runtime (no real mail transport), so the retry-identity check has no comparable success object. |

## Why no product findings

Every invariant this journey can fairly exercise on this runtime held:
idempotency (exact and changed-payload), identity-injection rejection,
venue-scoped idempotency keys, private-read authorization (cross-venue
denied, anonymous denied, operator allowed), invalid/stale/valid status
transitions, performance and deal selection, hold creation and
confirmation, configuration-revision conflicts, and cross-site block
rendering (main/studio/events all render the public booking-inquiry block
correctly, with context restored and no private data leaked).
