# Gardner venue-booking journey — findings (extrachill-network#292)

29 persona-oracle assertions, **29 passed**, 0 open findings, 4 skipped as
documented rig-environment limitations (never silently converted to a pass).
Full machine-readable result: `result.json`.

## Skips (not product defects)

| Case | Oracle | Reason |
| --- | --- | --- |
| `publish-to-calendar` | task-completion | `extrachill/convert-booking-to-event` returns `booking_event_ability_unavailable` (503, retryable): the canonical event upsert serializes on the MySQL-only `GET_LOCK` primitive, which this SQLite-backed rig does not provide. |
| `republish-does-not-duplicate` | duplicate-prevention | The first publish never ran in this runtime, so a duplicate cannot be observed. |
| `send-offer-email` | task-completion | `extrachill/send-booking-message` returns `booking_message_delivery_uncertain` — no real mail transport in this sandbox to confirm delivery. |
| `double-click-does-not-double-send` | duplicate-prevention | The first send has no comparable success object in this runtime, so the double-send identity check cannot be evaluated. |

## Why no product findings this time

Unlike `gardner-event-rsvp`'s first run (extrachill-network#291), this port
found zero open usability defects. The original single-site fixture's first
clean run (extrachill-events#771/c85d235) found nine failing oracles;
`extrachill-events#771` shipped the fixes before this port ever ran, so this
journey is verifying already-fixed behavior on the real network topology,
not discovering it fresh. Every illegal-shortcut, stale-tab, and boundary
case reads in plain language with no implementation jargon, and the hold
correctly blocks the competing request everywhere it should (console,
public availability checker, and the confirmed-booking view).
